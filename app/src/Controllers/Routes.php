<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Router;

/**
 * Route table. All routes require an authenticated session unless registered
 * with `public: true`; unsafe methods are CSRF-checked centrally by the
 * router guard (App\Security\AccessGuard). Endpoint paths are unchanged
 * compared to the former ApiController, plus the new /api/auth/* routes.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        $auth = new AuthController();
        $system = new SystemController();
        $models = new ModelController();
        $jobs = new JobController();
        $documents = new DocumentController();
        $files = new FileController();
        $exports = new ExportController();
        $tls = new TlsController();
        $search = new SearchController();

        // Public
        $router->get('/healthz', [$system, 'healthz'], public: true);
        $router->get('/api/auth/me', [$auth, 'me'], public: true);
        $router->get('/api/csrf', [$auth, 'csrf'], public: true);
        $router->post('/api/auth/login', [$auth, 'login'], public: true);
        $router->post('/api/auth/logout', [$auth, 'logout'], public: true);

        // Authenticated
        $router->post('/api/auth/password', [$auth, 'changePassword']);

        $router->get('/api/health', [$system, 'health']);
        $router->get('/api/system', [$system, 'system']);
        $router->get('/api/settings', [$system, 'settings']);
        $router->put('/api/settings', [$system, 'updateSettings']);
        $router->get('/api/integrity', [$system, 'integrity']);
        $router->get('/api/storage', [$system, 'storage']);
        $router->get('/api/statistics', [$system, 'statistics']);
        $router->get('/api/statistics/extensions', [$system, 'statisticsExtensions']);
        $router->get('/api/metrics', [$system, 'metrics']);
        $router->get('/api/collections', [$system, 'collections']);
        $router->get('/api/collections/{name}/stats', [$system, 'collectionStats']);

        $router->get('/api/models', [$models, 'index']);
        $router->post('/api/models/activate', [$models, 'activate']);
        $router->post('/api/models/sync', [$models, 'sync']);

        $router->get('/api/jobs', [$jobs, 'index']);
        $router->post('/api/jobs', [$jobs, 'create']);
        $router->get('/api/jobs/{id}', [$jobs, 'show']);
        $router->post('/api/jobs/{id}/cancel', [$jobs, 'cancel']);

        $router->get('/api/documents', [$documents, 'index']);
        $router->get('/api/documents/{id}', [$documents, 'show']);
        $router->delete('/api/documents/{id}', [$documents, 'delete']);
        $router->get('/api/documents/{id}/source', [$documents, 'source']);
        $router->get('/api/documents/{id}/versions', [$documents, 'versions']);
        $router->get('/api/documents/{id}/chunks', [$documents, 'chunks']);
        $router->get('/api/documents/{id}/vectors', [$documents, 'vectors']);
        $router->get('/api/documents/{id}/download', [$documents, 'download']);
        $router->get('/api/documents/{id}/metadata', [$documents, 'metadata']);
        $router->get('/api/vectors/{id}', [$documents, 'vector']);
        $router->get('/api/vectors/{id}/document', [$documents, 'vectorDocument']);
        $router->get('/api/chunks/{id}', [$documents, 'chunk']);
        $router->get('/api/chunks/{id}/source', [$documents, 'chunkSource']);
        $router->get('/api/source/{id}', [$documents, 'sourceByVersion']);

        $router->get('/api/browse', [$files, 'browse']);
        $router->post('/api/upload', [$files, 'upload']);

        $router->get('/api/exports', [$exports, 'index']);
        $router->post('/api/exports', [$exports, 'create']);
        $router->get('/api/exports/{id}/download', [$exports, 'download']);
        $router->post('/api/import', [$exports, 'import']);

        $router->get('/api/tls', [$tls, 'status']);
        $router->post('/api/tls/csr', [$tls, 'csr']);
        $router->post('/api/tls/selfsigned', [$tls, 'selfSigned']);
        $router->post('/api/tls/import', [$tls, 'import']);
        $router->post('/api/tls/{id}/activate', [$tls, 'activate']);

        $router->post('/api/search', [$search, 'search']);
    }
}
