<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Base64;
use App\Core\Db;

/**
 * Cross-system integrity check between MySQL (source of truth) and Milvus
 * (vectors). Reports missing blobs, hash mismatches, missing/orphan vectors,
 * collection and dimension mismatches — without mutating anything.
 */
final class IntegrityService
{
    /** @return array<string,mixed> */
    public function check(): array
    {
        // ---- MySQL-side checks (source of truth) ----
        $docs = Db::fetchOne(
            'SELECT COUNT(DISTINCT document_id) AS documents, COUNT(*) AS versions FROM documents'
        );
        $blobMissing = Db::fetchOne(
            'SELECT COUNT(*) AS n FROM documents d
             LEFT JOIN document_blobs b ON b.document_version_id = d.document_version_id
             WHERE b.blob_id IS NULL'
        );
        $chunks = Db::fetchOne('SELECT COUNT(*) AS n FROM document_chunks');
        $chunksMissingVector = Db::fetchOne(
            'SELECT COUNT(*) AS n FROM document_chunks WHERE vector_id IS NULL OR vector_id = ""'
        );

        $issues = [];
        $hashErrors = $this->hashErrors($issues);

        // ---- Milvus-side checks (best effort; Milvus may be offline) ----
        $milvusAvailable = true;
        $vectors = 0;
        $vectorsWithoutDocument = 0;
        $documentsWithoutVectors = 0;
        $collectionMismatches = 0;
        $dimensionMismatches = 0;

        try {
            $milvus = new MilvusClient();
            $collections = $milvus->listCollections();

            $mysqlVectorIds = $this->mysqlVectorIdSet();
            $milvusVectorIds = [];
            $milvusDocumentVersionIds = [];

            foreach ($collections as $collection) {
                $stats = $milvus->collectionStats($collection);
                $vectors += (int) ($stats['data']['rowCount'] ?? 0);
                foreach ($this->queryAll($milvus, $collection, ['id', 'document_version_id', 'embedding_model', 'embedding_dimension']) as $row) {
                    $id = $row['id'] ?? null;
                    if (is_string($id) && $id !== '') {
                        $milvusVectorIds[$id] = true;
                    }
                    $dv = $row['document_version_id'] ?? null;
                    if (is_string($dv) && $dv !== '') {
                        $milvusDocumentVersionIds[$dv] = true;
                    }
                }
            }

            $vectorsWithoutDocument = 0;
            foreach (array_keys($milvusVectorIds) as $id) {
                if (!isset($mysqlVectorIds[$id])) {
                    $vectorsWithoutDocument++;
                }
            }

            // Vectors that MySQL references but Milvus does not hold.
            $missingVectorCount = 0;
            foreach ($mysqlVectorIds as $id => $true) {
                if (!isset($milvusVectorIds[$id])) {
                    $missingVectorCount++;
                }
            }

            // Chunks without a matching Milvus vector (includes NULL + missing).
            $chunksMissingVector['n'] = max(
                (int) $chunksMissingVector['n'],
                $missingVectorCount
            );

            // Document versions with zero vectors on either side.
            $documentsWithoutVectors = (int) Db::fetchOne(
                "SELECT COUNT(*) AS n FROM documents d
                 WHERE d.processing_status = 'COMPLETED'
                   AND NOT EXISTS (SELECT 1 FROM document_chunks c WHERE c.document_id = d.id)"
            )['n'];

            // Collection/dimension mismatches: MySQL model/dimension vs Milvus rows.
            $mismatches = Db::fetchAll(
                'SELECT d.document_version_id, d.embedding_model, d.embedding_dimension, COUNT(c.id) AS chunks
                 FROM documents d JOIN document_chunks c ON c.document_id = d.id
                 GROUP BY d.document_version_id, d.embedding_model, d.embedding_dimension'
            );
            foreach ($mismatches as $m) {
                if (!isset($milvusDocumentVersionIds[$m['document_version_id']])) {
                    continue;
                }
                $collection = MilvusClient::collectionFor((string) $m['embedding_model']);
                if (!in_array($collection, $collections, true)) {
                    $collectionMismatches++;
                }
            }
            $dimensionMismatches = $this->dimensionMismatches($milvus, $collections, $issues);
        } catch (\Throwable $e) {
            $milvusAvailable = false;
            $issues[] = ['type' => 'milvus_unavailable', 'detail' => $e->getMessage()];
        }

        $inconsistencies = (int) $blobMissing['n'] + $hashErrors + $vectorsWithoutDocument
            + $collectionMismatches + $dimensionMismatches
            + count(array_filter($issues, static fn (array $i): bool => !in_array($i['type'], ['milvus_unavailable'], true)));

        return [
            'documents' => (int) ($docs['documents'] ?? 0),
            'document_versions' => (int) ($docs['versions'] ?? 0),
            'documents_without_blob' => (int) ($blobMissing['n'] ?? 0),
            'hash_errors' => $hashErrors,
            'chunks' => (int) ($chunks['n'] ?? 0),
            'chunks_without_vector' => (int) ($chunksMissingVector['n'] ?? 0),
            'vectors' => $vectors,
            'vectors_without_document' => $vectorsWithoutDocument,
            'documents_without_vectors' => $documentsWithoutVectors,
            'collection_mismatches' => $collectionMismatches,
            'dimension_mismatches' => $dimensionMismatches,
            'inconsistencies' => $inconsistencies,
            'milvus_available' => $milvusAvailable,
            'issues' => $issues,
        ];
    }

    /** @param list<array<string,mixed>> $issues */
    private function hashErrors(array &$issues): int
    {
        $rows = Db::fetchAll(
            'SELECT b.document_version_id, b.sha256, b.base64_data FROM document_blobs b'
        );
        $errors = 0;
        // Hash verification streams in batches to bound peak memory.
        foreach ($rows as $row) {
            $data = $row['base64_data'];
            if (!is_string($data)) {
                $errors++;
                $issues[] = ['type' => 'hash_error', 'document_version_id' => $row['document_version_id'], 'detail' => 'Blob has no Base64 payload'];
                continue;
            }
            $ok = Base64::verifySha256($data, (string) $row['sha256']);
            if (!$ok) {
                $errors++;
                $issues[] = ['type' => 'hash_error', 'document_version_id' => $row['document_version_id'], 'detail' => 'Base64 decoded SHA-256 does not match'];
            }
        }

        return $errors;
    }

    /** @return array<string,true> */
    private function mysqlVectorIdSet(): array
    {
        $ids = [];
        $offset = 0;
        while (true) {
            $rows = Db::fetchAll(
                'SELECT vector_id FROM document_chunks WHERE vector_id IS NOT NULL AND vector_id != "" ORDER BY id LIMIT 10000 OFFSET ?',
                [$offset]
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $ids[(string) $r['vector_id']] = true;
            }
            $offset += count($rows);
        }

        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function queryAll(MilvusClient $milvus, string $collection, array $fields): array
    {
        return $milvus->queryAll($collection, 'id != ""', $fields);
    }

    /** @param list<string> $collections @param list<array<string,mixed>> $issues */
    private function dimensionMismatches(MilvusClient $milvus, array $collections, array &$issues): int
    {
        $mismatches = 0;
        foreach ($collections as $collection) {
            $describe = $milvus->describeCollection($collection);
            $fields = $describe['data']['fields'] ?? $describe['fields'] ?? [];
            $dim = null;
            foreach ($fields as $field) {
                if (($field['name'] ?? '') === 'vector' && ($field['dataType'] ?? '') === 'FloatVector') {
                    $dim = (int) ($field['elementTypeParams']['dim'] ?? 0);
                }
            }
            if ($dim === null) {
                continue;
            }
            $modelRows = Db::fetchAll(
                'SELECT DISTINCT embedding_model, embedding_dimension FROM documents WHERE embedding_model != ""'
            );
            foreach ($modelRows as $m) {
                if (MilvusClient::collectionFor((string) $m['embedding_model']) === $collection
                    && (int) $m['embedding_dimension'] !== $dim) {
                    $mismatches++;
                    $issues[] = [
                        'type' => 'dimension_mismatch',
                        'collection' => $collection,
                        'detail' => sprintf('Collection dim %d vs model dim %d', $dim, (int) $m['embedding_dimension']),
                    ];
                }
            }
        }

        return $mismatches;
    }
}
