<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Uuid;
use App\Http\HttpException;

/**
 * Semantic search: embed the query, search the active model's Milvus
 * collection and hydrate the hits with chunk text and blob metadata from MySQL.
 * (Moved out of the controller, where it previously ran raw SQL inline.)
 */
final class SearchService
{
    public const MAX_QUERY_LENGTH = 2000;

    /**
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException for invalid input / missing model
     */
    public function search(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Bitte eine Suchanfrage eingeben.');
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Die Suchanfrage darf höchstens %d Zeichen lang sein.', self::MAX_QUERY_LENGTH));
        }
        $limit = max(1, min(50, $limit));

        $modelService = new ModelService();
        $model = $modelService->active() ?? $modelService->all()[0] ?? null;
        if ($model === null) {
            throw new \InvalidArgumentException('No embedding model available');
        }
        $modelName = (string) $model['name'];
        try {
            // Send the expected model: the service answers 409 if another
            // model is loaded instead of returning vectors of a foreign dimension.
            $vectors = (new EmbeddingClient())->embedBatch([$query], $modelName);
        } catch (ModelMismatchException $e) {
            throw HttpException::conflict($e->getMessage() . ' Bitte das Modell unter „System“ aktivieren oder warten, bis der laufende Auftrag abgeschlossen ist.');
        }
        $vector = $vectors[0] ?? null;
        if (!is_array($vector) || $vector === []) {
            throw new \App\Http\UpstreamException('Embedding service returned no vector for the query');
        }
        EmbeddingClient::assertDimension([$vector], (int) $model['dimension'], $modelName);
        $milvus = new MilvusClient();
        $collection = MilvusClient::collectionFor($modelName);
        if (!$milvus->hasCollection($collection)) {
            return [];
        }
        $metric = (string) $model['distance_metric'];

        // Old document versions keep their vectors (immutable history), so
        // Milvus returns hits for retired versions too. Over-fetch candidates
        // and keep only chunks whose version is still `is_current = 1`; if the
        // candidate set is exhausted, escalate once with a larger window.
        $rows = [];
        foreach (self::candidateWindows($limit) as $window) {
            $result = $milvus->search($collection, [$vector], $window, $metric);
            $candidates = array_values(array_filter($result['data'] ?? [], 'is_array'));
            $rows = self::filterCurrent($candidates, [$this, 'currentVersionIds'], $limit);
            if (count($rows) >= $limit || count($candidates) < $window) {
                break;
            }
        }

        return $this->hydrate(['data' => $rows]);
    }

    /**
     * Candidate window sizes for a requested result count.
     *
     * @return list<int>
     */
    public static function candidateWindows(int $limit): array
    {
        return [min(250, max($limit * 5, 20)), 1000];
    }

    /**
     * Keep the first $limit hits whose `document_version_id` is current.
     * Pure apart from the injected lookup so the rule can be unit-tested.
     *
     * @param list<array<string,mixed>> $hits ordered Milvus hits
     * @param callable(list<string>):array<string,true> $currentLookup returns the subset of version ids that are current
     * @return list<array<string,mixed>>
     */
    public static function filterCurrent(array $hits, callable $currentLookup, int $limit): array
    {
        $versionIds = [];
        foreach ($hits as $hit) {
            $v = (string) ($hit['document_version_id'] ?? '');
            if ($v !== '') {
                $versionIds[$v] = true;
            }
        }
        $current = $versionIds === [] ? [] : $currentLookup(array_keys($versionIds));

        $kept = [];
        foreach ($hits as $hit) {
            $v = (string) ($hit['document_version_id'] ?? '');
            if ($v === '' || !isset($current[$v])) {
                continue;
            }
            $kept[] = $hit;
            if (count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $versionIds
     * @return array<string,true>
     */
    private function currentVersionIds(array $versionIds): array
    {
        $valid = array_values(array_filter($versionIds, static fn (string $v): bool => Uuid::isValid($v)));
        if ($valid === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($valid), '?'));
        $rows = Db::fetchAll(
            "SELECT document_version_id FROM documents WHERE is_current = 1 AND document_version_id IN ($placeholders)",
            $valid
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['document_version_id']] = true;
        }

        return $out;
    }

    /** @param array<string,mixed> $result @return list<array<string,mixed>> */
    private function hydrate(array $result): array
    {
        $hits = [];
        foreach ($result['data'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $chunkId = (string) ($row['chunk_id'] ?? '');
            $documentId = (string) ($row['document_id'] ?? '');
            $versionId = (string) ($row['document_version_id'] ?? '');
            $chunk = $chunkId !== '' ? Db::fetchOne('SELECT text FROM document_chunks WHERE chunk_id = ? LIMIT 1', [$chunkId]) : null;
            $blob = $versionId !== ''
                ? Db::fetchOne('SELECT sha256, file_size, mime_type FROM document_blobs WHERE document_version_id = ? LIMIT 1', [$versionId])
                : null;
            $hits[] = [
                'vector_id' => $row['id'] ?? null,
                'distance' => $row['distance'] ?? null,
                'chunk_id' => $chunkId !== '' ? $chunkId : null,
                'chunk_index' => $row['chunk_index'] ?? null,
                'page_start' => $row['page_start'] ?? null,
                'page_end' => $row['page_end'] ?? null,
                'text' => $chunk['text'] ?? null,
                'document_id' => $documentId !== '' ? $documentId : null,
                'document_version_id' => $versionId !== '' ? $versionId : null,
                'filename' => $row['filename'] ?? null,
                'source_path' => $row['source_path'] ?? null,
                'document_hash' => $row['document_hash'] ?? ($blob['sha256'] ?? null),
                'file_size' => $blob['file_size'] ?? null,
                'mime_type' => $blob['mime_type'] ?? null,
                'embedding_model' => $row['embedding_model'] ?? null,
                'embedding_dimension' => $row['embedding_dimension'] ?? null,
                'download_endpoint' => $documentId !== '' ? '/api/documents/' . rawurlencode($documentId) . '/download' : null,
            ];
        }

        return $hits;
    }
}
