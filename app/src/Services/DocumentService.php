<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Core\Uuid;

/**
 * Version-aware document access. `document_id` is the stable logical identity
 * of a document; `document_version_id` is the immutable identity of one stored
 * version. Each version has its own blob, chunks and vectors.
 */
final class DocumentService
{
    /** @return list<array<string,mixed>> */
    public function list(?int $jobId = null, int $limit = 100, int $offset = 0, string $search = '', bool $includeOldVersions = false): array
    {
        $sql = 'SELECT d.* FROM documents d WHERE 1=1';
        $params = [];
        if (!$includeOldVersions) {
            $sql .= ' AND d.is_current = 1';
        }
        if ($jobId !== null) {
            $sql .= ' AND d.job_id = ?';
            $params[] = $jobId;
        }
        if ($search !== '') {
            // Escape LIKE wildcards so the search term is matched literally.
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $sql .= ' AND (d.filename LIKE ? OR d.relative_path LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY d.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return Db::fetchAll($sql, $params);
    }

    /** Return the current version of a logical document. */
    public function getByUuid(string $documentId): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM documents WHERE document_id = ? AND is_current = 1 LIMIT 1',
            [$documentId]
        );
    }

    /** Return any version of a logical document (fallback to newest). */
    public function getAnyVersion(string $documentId): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM documents WHERE document_id = ? ORDER BY version DESC LIMIT 1',
            [$documentId]
        );
    }

    /** Return a precise immutable version by its version id. */
    public function getVersion(string $documentVersionId): ?array
    {
        return Db::fetchOne('SELECT * FROM documents WHERE document_version_id = ? LIMIT 1', [$documentVersionId]);
    }

    /** @return list<array<string,mixed>> */
    public function versions(string $documentId): array
    {
        return Db::fetchAll(
            'SELECT * FROM documents WHERE document_id = ? ORDER BY version ASC',
            [$documentId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function chunks(string $documentId, int $limit = 500): array
    {
        $doc = $this->getByUuid($documentId);

        return $doc === null ? [] : $this->chunksByVersion((string) $doc['document_version_id'], $limit);
    }

    /** @return list<array<string,mixed>> */
    public function chunksByVersion(string $documentVersionId, int $limit = 500): array
    {
        $doc = $this->getVersion($documentVersionId);
        if ($doc === null) {
            return [];
        }

        return Db::fetchAll(
            'SELECT chunk_id, vector_id, chunk_index, page_start, page_end, text_length, token_count, text
             FROM document_chunks WHERE document_id = ? ORDER BY chunk_index ASC LIMIT ?',
            [$doc['id'], $limit]
        );
    }

    /** Return the original-file blob payload (Base64) + metadata for a version. */
    public function source(string $documentVersionId): ?array
    {
        $doc = $this->getVersion($documentVersionId);
        if ($doc === null) {
            return null;
        }

        $blob = Db::fetchOne(
            'SELECT * FROM document_blobs WHERE document_version_id = ? LIMIT 1',
            [$documentVersionId]
        );

        return [
            'document_id' => $doc['document_id'],
            'document_version_id' => $doc['document_version_id'],
            'version' => (int) $doc['version'],
            'filename' => $doc['filename'],
            'original_filename' => $blob['original_filename'] ?? $doc['filename'],
            'mime_type' => $blob['mime_type'] ?? $doc['mime_type'],
            'encoding' => $blob['encoding'] ?? 'base64',
            'file_size' => (int) ($blob['file_size'] ?? $doc['file_size']),
            'sha256' => $blob['sha256'] ?? $doc['file_hash'],
            'source_path' => $doc['source_path'],
            'relative_path' => $doc['relative_path'],
            'page_count' => (int) $doc['page_count'],
            'base64' => $blob['base64_data'] ?? null,
            'source_available' => $blob !== null,
        ];
    }

    /**
     * Decode and return the original bytes for a version (byte-for-byte).
     *
     * @return array{bytes:string,filename:string,mime_type:string,sha256:string}|null
     */
    public function download(string $documentVersionId): ?array
    {
        $source = $this->source($documentVersionId);
        if ($source === null || !$source['source_available'] || !is_string($source['base64'])) {
            return null;
        }
        $decoded = base64_decode((string) $source['base64'], true);
        if ($decoded === false) {
            return null;
        }
        // Confirm byte integrity before serving (never serve corrupt data).
        if (hash('sha256', $decoded) !== (string) $source['sha256']) {
            return null;
        }

        return [
            'bytes' => $decoded,
            'filename' => (string) ($source['original_filename'] ?: $source['filename']),
            'mime_type' => (string) $source['mime_type'],
            'sha256' => (string) $source['sha256'],
        ];
    }

    /** Return decoded metadata JSON (or empty array). */
    public function metadata(string $documentVersionId): array
    {
        $doc = $this->getVersion($documentVersionId);
        if ($doc === null) {
            return [];
        }
        $raw = $doc['metadata'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string,mixed>> */
    public function vectors(string $documentId): array
    {
        $doc = $this->getByUuid($documentId);

        return $doc === null ? [] : $this->vectorsByVersion((string) $doc['document_version_id']);
    }

    /** @return list<array<string,mixed>> */
    public function vectorsByVersion(string $documentVersionId): array
    {
        $doc = $this->getVersion($documentVersionId);
        if ($doc === null) {
            return [];
        }

        return Db::fetchAll(
            'SELECT chunk_id, vector_id, chunk_index, page_start, page_end, text_length, token_count
             FROM document_chunks WHERE document_id = ? ORDER BY chunk_index ASC',
            [$doc['id']]
        );
    }

    /** Delete a logical document: all versions, blobs and (cascade) chunks. */
    public function delete(string $documentId): ?array
    {
        $doc = $this->getAnyVersion($documentId);
        if ($doc === null) {
            return null;
        }
        Db::transaction(function () use ($documentId): void {
            Db::execute('DELETE FROM document_blobs WHERE document_id = ?', [$documentId]);
            Db::execute('DELETE FROM documents WHERE document_id = ?', [$documentId]);
        });

        // Remove vectors from Milvus as well. MySQL is authoritative, so a
        // Milvus failure is logged (and shows up in the integrity check) but
        // does not undo the deletion. The id is a validated UUID taken from
        // our own DB row, so it is safe inside the filter expression.
        if (!empty($doc['embedding_model']) && Uuid::isValid((string) $doc['document_id'])) {
            try {
                (new MilvusClient())->deleteByFilter(
                    MilvusClient::collectionFor((string) $doc['embedding_model']),
                    sprintf('document_id == "%s"', $doc['document_id'])
                );
            } catch (\Throwable $e) {
                Logger::channel('documents')->warning('Milvus cleanup failed', [
                    'document_id' => $doc['document_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        Audit::record('document.delete', 'document', $documentId);

        return $doc;
    }

    /** @return array<string,mixed>|null */
    public function vectorById(string $vectorId): ?array
    {
        return Db::fetchOne(
            'SELECT c.vector_id, c.chunk_id, c.chunk_index, c.page_start, c.page_end, c.text_length, c.token_count, d.document_id, d.document_version_id
             FROM document_chunks c JOIN documents d ON d.id = c.document_id
             WHERE c.vector_id = ? LIMIT 1',
            [$vectorId]
        );
    }

    /** @return array<string,mixed>|null */
    public function chunkById(string $chunkId): ?array
    {
        return Db::fetchOne(
            'SELECT c.chunk_id, c.vector_id, c.chunk_index, c.page_start, c.page_end, c.text_length, c.token_count, c.text,
                                d.document_id, d.document_version_id
             FROM document_chunks c JOIN documents d ON d.id = c.document_id
             WHERE c.chunk_id = ? LIMIT 1',
            [$chunkId]
        );
    }

    /** Source metadata without the (potentially huge) Base64 payload. */
    public function sourceSummary(string $documentVersionId): ?array
    {
        $source = $this->source($documentVersionId);
        if ($source !== null) {
            unset($source['base64']);
        }

        return $source;
    }

    /** @return array{total:int,versions:int,processed:int,failed:int,pending:int,multi_version:int} */
    public function counts(): array
    {
        $row = Db::fetchOne(
            'SELECT COUNT(DISTINCT document_id) AS total,
                    COUNT(*) AS versions,
                    SUM(is_current = 1 AND processing_status = "COMPLETED") AS processed,
                    SUM(is_current = 1 AND processing_status = "FAILED") AS failed,
                    SUM(is_current = 1 AND processing_status IN ("DISCOVERED","PENDING","PROCESSING")) AS pending
             FROM documents'
        );
        $multi = Db::fetchOne(
            'SELECT COUNT(*) AS multi FROM (SELECT document_id FROM documents GROUP BY document_id HAVING COUNT(*) > 1) AS m'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'versions' => (int) ($row['versions'] ?? 0),
            'processed' => (int) ($row['processed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'multi_version' => (int) ($multi['multi'] ?? 0),
        ];
    }
}
