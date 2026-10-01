<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\DocumentService;
use App\Services\JobService;

/** Documents, versions, chunks, vectors and source references. */
final class DocumentController extends Controller
{
    private DocumentService $documents;

    public function __construct()
    {
        $this->documents = new DocumentService();
    }

    public function index(Request $request): Response
    {
        $jobId = $request->queryInt('job_id', 0, 0, PHP_INT_MAX);
        $search = mb_substr(trim($request->query('search', '')), 0, 255);
        $limit = $request->queryInt('limit', 100, 1, 500);
        $offset = $request->queryInt('offset', 0, 0, PHP_INT_MAX);

        return Response::json(['documents' => $this->documents->list($jobId > 0 ? $jobId : null, $limit, $offset, $search)]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $doc = $this->current($params);
        $id = (string) $doc['document_id'];
        $doc['chunks'] = $this->documents->chunks($id);
        $doc['versions'] = $this->documents->versions($id);
        $doc['source'] = $this->documents->sourceSummary((string) $doc['document_version_id']);

        return Response::json(['document' => $doc]);
    }

    /** @param array<string,string> $params */
    public function delete(Request $request, array $params): Response
    {
        $id = $this->uuidParam($params, 'id', 'Document not found');
        // Milvus cleanup + audit entry moved into DocumentService::delete().
        if ($this->documents->delete($id) === null) {
            throw HttpException::notFound('Document not found');
        }

        return Response::json(['deleted' => true]);
    }

    /**
     * Re-process a FAILED document (creates a running one-document job).
     *
     * @param array<string,string> $params
     */
    public function retry(Request $request, array $params): Response
    {
        $doc = $this->current($params);
        $job = (new JobService())->retryDocument($doc);

        return Response::json(['job' => $job, 'document_id' => (string) $doc['document_id']], 202);
    }

    /** @param array<string,string> $params */
    public function source(Request $request, array $params): Response
    {
        $doc = $this->current($params);

        return Response::json(['source' => $this->documents->sourceSummary((string) $doc['document_version_id'])]);
    }

    /** @param array<string,string> $params */
    public function versions(Request $request, array $params): Response
    {
        $id = $this->uuidParam($params, 'id', 'Document not found');
        $versions = $this->documents->versions($id);
        if ($versions === []) {
            throw HttpException::notFound('Document not found');
        }

        return Response::json(['document_id' => $id, 'versions' => $versions]);
    }

    /** @param array<string,string> $params */
    public function chunks(Request $request, array $params): Response
    {
        $doc = $this->current($params);

        return Response::json(['chunks' => $this->documents->chunksByVersion((string) $doc['document_version_id'])]);
    }

    /** @param array<string,string> $params */
    public function vectors(Request $request, array $params): Response
    {
        $doc = $this->current($params);

        return Response::json(['vectors' => $this->documents->vectorsByVersion((string) $doc['document_version_id'])]);
    }

    /** @param array<string,string> $params */
    public function download(Request $request, array $params): Response
    {
        $doc = $this->current($params);
        $download = $this->documents->download((string) $doc['document_version_id']);
        if ($download === null) {
            throw HttpException::notFound('Original not available');
        }

        // FIX: RFC 6266 filename encoding instead of addslashes() (header
        // injection / broken non-ASCII names) + nosniff + sandbox CSP.
        return Response::download($download['bytes'], $download['filename'], $download['mime_type']);
    }

    /** @param array<string,string> $params */
    public function metadata(Request $request, array $params): Response
    {
        $doc = $this->current($params);

        return Response::json(['metadata' => $this->documents->metadata((string) $doc['document_version_id'])]);
    }

    /** @param array<string,string> $params */
    public function vector(Request $request, array $params): Response
    {
        $vector = $this->documents->vectorById($this->uuidParam($params, 'id', 'Vector not found'));
        if ($vector === null) {
            throw HttpException::notFound('Vector not found');
        }

        return Response::json(['vector' => $vector]);
    }

    /** @param array<string,string> $params */
    public function vectorDocument(Request $request, array $params): Response
    {
        $vector = $this->documents->vectorById($this->uuidParam($params, 'id', 'Vector not found'));
        if ($vector === null) {
            throw HttpException::notFound('Vector not found');
        }
        $doc = $this->documents->getVersion((string) $vector['document_version_id']);
        if ($doc === null) {
            throw HttpException::notFound('Document not found');
        }

        return Response::json(['document' => $doc]);
    }

    /** @param array<string,string> $params */
    public function chunk(Request $request, array $params): Response
    {
        $chunk = $this->documents->chunkById($this->uuidParam($params, 'id', 'Chunk not found'));
        if ($chunk === null) {
            throw HttpException::notFound('Chunk not found');
        }

        return Response::json(['chunk' => $chunk]);
    }

    /** @param array<string,string> $params */
    public function chunkSource(Request $request, array $params): Response
    {
        $chunk = $this->documents->chunkById($this->uuidParam($params, 'id', 'Chunk not found'));
        if ($chunk === null) {
            throw HttpException::notFound('Chunk not found');
        }

        return Response::json(['source' => $this->documents->sourceSummary((string) $chunk['document_version_id'])]);
    }

    /** @param array<string,string> $params */
    public function sourceByVersion(Request $request, array $params): Response
    {
        $versionId = $this->uuidParam($params, 'id', 'Document version not found');
        $source = $this->documents->sourceSummary($versionId);
        if ($source === null) {
            throw HttpException::notFound('Document version not found');
        }
        $source['chunks'] = array_map(
            static fn (array $c): array => ['chunk_id' => $c['chunk_id'], 'page_start' => (int) $c['page_start'], 'page_end' => (int) $c['page_end']],
            $this->documents->chunksByVersion($versionId)
        );
        $source['download_endpoint'] = '/api/documents/' . rawurlencode((string) $source['document_id']) . '/download';

        return Response::json($source);
    }

    /**
     * Current version of the document addressed by the {id} route parameter.
     *
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function current(array $params): array
    {
        $doc = $this->documents->getByUuid($this->uuidParam($params, 'id', 'Document not found'));
        if ($doc === null) {
            throw HttpException::notFound('Document not found');
        }

        return $doc;
    }
}
