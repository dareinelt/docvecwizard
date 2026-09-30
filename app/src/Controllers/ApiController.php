<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Security\Csrf;
use App\Security\PathGuard;
use App\Services\Audit;
use App\Services\DirectoryBrowser;
use App\Services\DocumentService;
use App\Services\EmbeddingClient;
use App\Services\ExportService;
use App\Services\JobService;
use App\Services\MilvusClient;
use App\Services\ModelService;
use App\Services\SettingsService;
use App\Services\StatisticsService;
use App\Services\SystemService;
use App\Services\TlsService;

final class ApiController
{
    public function register(Router $router): void
    {
        $router->get('/healthz', [$this, 'healthz']);
        $router->get('/api/health', [$this, 'health']);
        $router->get('/api/csrf', [$this, 'csrf']);
        $router->get('/api/system', [$this, 'system']);

        $router->get('/api/settings', [$this, 'settings']);
        $router->put('/api/settings', [$this, 'updateSettings']);

        $router->get('/api/models', [$this, 'models']);
        $router->post('/api/models/activate', [$this, 'activateModel']);
        $router->post('/api/models/sync', [$this, 'syncModels']);

        $router->get('/api/jobs', [$this, 'jobs']);
        $router->post('/api/jobs', [$this, 'createJob']);
        $router->get('/api/jobs/{id}', [$this, 'job']);
        $router->post('/api/jobs/{id}/cancel', [$this, 'cancelJob']);

        $router->get('/api/documents', [$this, 'documents']);
        $router->get('/api/documents/{id}', [$this, 'document']);
        $router->delete('/api/documents/{id}', [$this, 'deleteDocument']);

        $router->get('/api/browse', [$this, 'browse']);
        $router->post('/api/upload', [$this, 'upload']);

        $router->get('/api/statistics', [$this, 'statistics']);
        $router->get('/api/statistics/extensions', [$this, 'statisticsExtensions']);

        $router->get('/api/collections', [$this, 'collections']);
        $router->get('/api/collections/{name}/stats', [$this, 'collectionStats']);

        $router->get('/api/exports', [$this, 'exports']);
        $router->post('/api/exports', [$this, 'createExport']);
        $router->get('/api/exports/{id}/download', [$this, 'downloadExport']);
        $router->post('/api/import', [$this, 'import']);

        $router->get('/api/tls', [$this, 'tlsStatus']);
        $router->post('/api/tls/csr', [$this, 'tlsCsr']);
        $router->post('/api/tls/selfsigned', [$this, 'tlsSelfSigned']);
        $router->post('/api/tls/import', [$this, 'tlsImport']);
        $router->post('/api/tls/{id}/activate', [$this, 'tlsActivate']);

        $router->get('/api/metrics', [$this, 'metrics']);
        $router->post('/api/search', [$this, 'search']);
    }

    public function healthz(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    public function health(Request $request): Response
    {
        return Response::json((new SystemService())->health());
    }

    public function csrf(Request $request): Response
    {
        return Response::json(['csrf_token' => Csrf::token()]);
    }

    public function system(Request $request): Response
    {
        return Response::json((new SystemService())->info());
    }

    public function settings(Request $request): Response
    {
        return Response::json(['settings' => (new SettingsService())->all()]);
    }

    public function updateSettings(Request $request): Response
    {
        $this->guard($request);
        $values = $request->body['settings'] ?? null;
        if (!is_array($values)) {
            return Response::error('Expected "settings" object', 400);
        }
        $service = new SettingsService();
        foreach ($values as $key => $value) {
            $service->set((string) $key, (string) $value);
        }
        Audit::record('settings.update');

        return Response::json(['settings' => $service->all()]);
    }

    public function models(Request $request): Response
    {
        return Response::json(['models' => (new ModelService())->all()]);
    }

    public function activateModel(Request $request): Response
    {
        $this->guard($request);
        $name = (string) $request->bodyField('name', '');
        if ($name === '') {
            return Response::error('Missing model name', 400);
        }
        try {
            return Response::json(['model' => (new ModelService())->activate($name)]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function syncModels(Request $request): Response
    {
        $this->guard($request);
        $count = (new ModelService())->syncFromCatalog(new EmbeddingClient());

        return Response::json(['synced' => $count]);
    }

    public function jobs(Request $request): Response
    {
        $limit = min(500, max(1, (int) $request->query('limit', '100')));
        $offset = max(0, (int) $request->query('offset', '0'));

        return Response::json(['jobs' => (new JobService())->list($limit, $offset)]);
    }

    public function createJob(Request $request): Response
    {
        $this->guard($request);
        try {
            $job = (new JobService())->create([
                'name' => (string) $request->bodyField('name', ''),
                'source_directory' => (string) $request->bodyField('source_directory', ''),
                'recursive' => (bool) $request->bodyField('recursive', true),
                'embedding_model' => (string) $request->bodyField('embedding_model', ''),
            ]);

            return Response::json(['job' => $job], 201);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function job(Request $request, array $params): Response
    {
        $job = (new JobService())->getByUuid($params['id']);

        return $job === null ? Response::error('Job not found', 404) : Response::json(['job' => $job]);
    }

    public function cancelJob(Request $request, array $params): Response
    {
        $this->guard($request);
        $job = (new JobService())->getByUuid($params['id']);
        if ($job === null) {
            return Response::error('Job not found', 404);
        }
        (new JobService())->cancel((int) $job['id']);

        return Response::json(['job' => (new JobService())->getByUuid($params['id'])]);
    }

    public function documents(Request $request): Response
    {
        $jobId = $request->query('job_id', '');
        $search = $request->query('search', '');
        $limit = min(500, max(1, (int) $request->query('limit', '100')));
        $offset = max(0, (int) $request->query('offset', '0'));

        return Response::json(['documents' => (new DocumentService())->list($jobId !== '' ? (int) $jobId : null, $limit, $offset, $search)]);
    }

    public function document(Request $request, array $params): Response
    {
        $service = new DocumentService();
        $doc = $service->getByUuid($params['id']);
        if ($doc === null) {
            return Response::error('Document not found', 404);
        }
        $doc['chunks'] = $service->chunks($params['id']);

        return Response::json(['document' => $doc]);
    }

    public function deleteDocument(Request $request, array $params): Response
    {
        $this->guard($request);
        $doc = (new DocumentService())->delete($params['id']);
        if ($doc === null) {
            return Response::error('Document not found', 404);
        }
        // Remove vectors from Milvus if the document was embedded.
        if (!empty($doc['embedding_model']) && (int) $doc['chunk_count'] > 0) {
            try {
                $milvus = new MilvusClient();
                $collection = MilvusClient::collectionFor((string) $doc['embedding_model']);
                $milvus->deleteByFilter($collection, sprintf('document_id == "%s"', $doc['document_id']));
            } catch (\Throwable $e) {
                // Best-effort: DB deletion is authoritative; log and continue.
                error_log('[delete] Milvus cleanup failed: ' . $e->getMessage());
            }
        }
        Audit::record('document.delete', 'document', $params['id']);

        return Response::json(['deleted' => true]);
    }

    public function browse(Request $request): Response
    {
        $path = $request->query('path', '');
        try {
            return Response::json((new DirectoryBrowser())->list($path));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function upload(Request $request): Response
    {
        $this->guard($request);
        $target = PathGuard::normalise((string) $request->bodyField('path', ''));
        $root = Config::string('INPUT_ROOT', '/srv/data/input');
        try {
            $dir = PathGuard::resolve($root, $target);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
        if (!is_dir($dir)) {
            return Response::error('Target directory does not exist', 400);
        }
        $saved = [];
        foreach ($request->files as $file) {
            if (!is_array($file) || empty($file['name']) || empty($file['tmp_name'])) {
                continue;
            }
            $name = basename((string) $file['name']);
            if (!PathGuard::validateName($name)) {
                return Response::error('Invalid file name', 400);
            }
            $dest = $dir . '/' . $name;
            if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
                return Response::error('Failed to store ' . $name, 500);
            }
            $saved[] = $name;
        }
        Audit::record('document.upload', 'directory', $target, ['files' => $saved]);

        return Response::json(['uploaded' => $saved], 201);
    }

    public function statistics(Request $request): Response
    {
        return Response::json((new StatisticsService())->dashboard());
    }

    public function statisticsExtensions(Request $request): Response
    {
        return Response::json(['extensions' => (new StatisticsService())->documentsByExtension()]);
    }

    public function collections(Request $request): Response
    {
        try {
            return Response::json(['collections' => (new MilvusClient())->listCollections()]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 502);
        }
    }

    public function collectionStats(Request $request, array $params): Response
    {
        try {
            return Response::json((new MilvusClient())->collectionStats($params['name']));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 502);
        }
    }

    public function exports(Request $request): Response
    {
        return Response::json(['exports' => (new ExportService())->list()]);
    }

    public function createExport(Request $request): Response
    {
        $this->guard($request);
        try {
            return Response::json(['export' => (new ExportService())->create()], 201);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function downloadExport(Request $request, array $params): Response
    {
        $path = (new ExportService())->download($params['id']);
        if ($path === null) {
            return Response::error('Export not found', 404);
        }
        $body = (string) file_get_contents($path);

        return new Response(200, $body, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Content-Length' => (string) filesize($path),
        ]);
    }

    public function import(Request $request): Response
    {
        $this->guard($request);
        $manifest = $request->body['manifest'] ?? null;
        if (!is_array($manifest) && !empty($request->files)) {
            $file = reset($request->files);
            if (is_array($file) && !empty($file['tmp_name'])) {
                $raw = (string) file_get_contents((string) $file['tmp_name']);
                $decoded = json_decode($raw, true);
                $manifest = is_array($decoded) ? $decoded : null;
            }
        }
        if (!is_array($manifest)) {
            return Response::error('Expected "manifest" object or manifest.json upload', 400);
        }
        try {
            return Response::json((new ExportService())->import($manifest));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function tlsStatus(Request $request): Response
    {
        return Response::json((new TlsService())->status());
    }

    public function tlsCsr(Request $request): Response
    {
        $this->guard($request);
        $service = new TlsService();
        $san = $request->bodyField('san', []);
        if (!is_array($san)) {
            $san = [];
        }
        try {
            return Response::json(['certificate' => $service->createCsr(
                (string) $request->bodyField('common_name', ''),
                $san,
                (string) $request->bodyField('key_type', 'rsa3072'),
            )], 201);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function tlsSelfSigned(Request $request): Response
    {
        $this->guard($request);
        $service = new TlsService();
        $san = $request->bodyField('san', []);
        if (!is_array($san)) {
            $san = [];
        }
        try {
            return Response::json(['certificate' => $service->generateSelfSigned(
                (string) $request->bodyField('common_name', ''),
                $san,
                (string) $request->bodyField('key_type', 'rsa3072'),
            )], 201);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function tlsImport(Request $request): Response
    {
        $this->guard($request);
        $certPem = (string) $request->bodyField('cert_pem', '');
        if ($certPem === '') {
            return Response::error('Missing cert_pem', 400);
        }
        try {
            return Response::json(['certificate' => (new TlsService())->importCert($certPem)]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function tlsActivate(Request $request, array $params): Response
    {
        $this->guard($request);
        try {
            (new TlsService())->activate((int) $params['id']);

            return Response::json(['status' => (new TlsService())->status()]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 400);
        }
    }

    public function metrics(Request $request): Response
    {
        $service = $request->query('service', '');

        return Response::json(['metrics' => (new SystemService())->recentMetrics($service)]);
    }

    public function search(Request $request): Response
    {
        $this->guard($request);
        $query = (string) $request->bodyField('query', '');
        $limit = min(50, max(1, (int) $request->bodyField('limit', 10)));
        if (trim($query) === '') {
            return Response::error('Missing query', 400);
        }
        try {
            $modelService = new ModelService();
            $model = $modelService->active() ?? $modelService->all()[0] ?? null;
            if ($model === null) {
                return Response::error('No embedding model available', 400);
            }
            $embedding = new EmbeddingClient();
            $embedded = $embedding->embedBatch([$query]);
            $vector = $embedded[0] ?? null;
            if (!is_array($vector) || $vector === []) {
                return Response::error('Failed to embed query', 502);
            }
            $milvus = new MilvusClient();
            $collection = MilvusClient::collectionFor((string) $model['name']);
            $result = $milvus->search($collection, [$vector], $limit, (string) $model['distance_metric']);

            return Response::json(['results' => $this->hydrateSearchResults($result)]);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage(), 502);
        }
    }

    /** @param array<string,mixed> $result @return list<array<string,mixed>> */
    private function hydrateSearchResults(array $result): array
    {
        $hits = [];
        foreach ($result['data'] ?? [] as $row) {
            $hits[] = [
                'distance' => $row['distance'] ?? null,
                'id' => $row['id'] ?? null,
                'document_id' => $row['document_id'] ?? null,
                'chunk_index' => $row['chunk_index'] ?? null,
            ];
        }

        return $hits;
    }

    private function guard(Request $request): void
    {
        $token = $request->header('x-csrf-token');
        if (!Csrf::verify($token)) {
            throw new \App\Security\CsrfException();
        }
    }
}
