<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class DocumentService
{
    /** @return list<array<string,mixed>> */
    public function list(?int $jobId = null, int $limit = 100, int $offset = 0, string $search = ''): array
    {
        $sql = 'SELECT d.* FROM documents d WHERE 1=1';
        $params = [];
        if ($jobId !== null) {
            $sql .= ' AND d.job_id = ?';
            $params[] = $jobId;
        }
        if ($search !== '') {
            $sql .= ' AND (d.filename LIKE ? OR d.relative_path LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY d.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return Db::fetchAll($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public function getByUuid(string $documentId): ?array
    {
        return Db::fetchOne('SELECT * FROM documents WHERE document_id = ?', [$documentId]);
    }

    /** @return list<array<string,mixed>> */
    public function chunks(string $documentId, int $limit = 500): array
    {
        $doc = $this->getByUuid($documentId);
        if ($doc === null) {
            return [];
        }

        return Db::fetchAll(
            'SELECT chunk_id, chunk_index, page_start, page_end, text_length, token_count, text
             FROM document_chunks WHERE document_id = ? ORDER BY chunk_index ASC LIMIT ?',
            [$doc['id'], $limit]
        );
    }

    /** Delete a document and (via cascade) its chunks + Milvus vectors handled by caller. */
    public function delete(string $documentId): ?array
    {
        $doc = $this->getByUuid($documentId);
        if ($doc === null) {
            return null;
        }
        Db::execute('DELETE FROM documents WHERE document_id = ?', [$documentId]);

        return $doc;
    }

    /** @return array{total:int,processed:int,failed:int,pending:int} */
    public function counts(): array
    {
        $row = Db::fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(processing_status = "COMPLETED") AS processed,
                    SUM(processing_status = "FAILED") AS failed,
                    SUM(processing_status IN ("DISCOVERED","PENDING","PROCESSING")) AS pending
             FROM documents'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'processed' => (int) ($row['processed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }
}
