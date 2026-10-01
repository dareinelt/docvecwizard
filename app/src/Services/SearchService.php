<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
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
        $result = $milvus->search($collection, [$vector], $limit, (string) $model['distance_metric']);

        return $this->hydrate($result);
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
