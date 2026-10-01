<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\Audit;
use App\Services\IntegrityService;
use App\Services\MilvusClient;
use App\Services\SettingsService;
use App\Services\StatisticsService;
use App\Services\SystemService;

/** Health, system info, settings, statistics, metrics and Milvus collections. */
final class SystemController extends Controller
{
    /** Public liveness probe (used by the web container healthcheck). */
    public function healthz(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    public function health(Request $request): Response
    {
        return Response::json((new SystemService())->health());
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
        $values = $request->bodyField('settings');
        if (!is_array($values)) {
            throw HttpException::badRequest('Expected "settings" object');
        }
        $service = new SettingsService();
        // FIX: keys/values are validated (previously arbitrary keys and
        // array values cast to the string "Array" were stored).
        $service->update($values);
        Audit::record('settings.update', 'settings', null, ['keys' => array_keys($values)]);

        return Response::json(['settings' => $service->all()]);
    }

    public function integrity(Request $request): Response
    {
        return Response::json((new IntegrityService())->check());
    }

    public function storage(Request $request): Response
    {
        return Response::json((new StatisticsService())->storage());
    }

    public function statistics(Request $request): Response
    {
        return Response::json((new StatisticsService())->dashboard());
    }

    public function statisticsExtensions(Request $request): Response
    {
        return Response::json(['extensions' => (new StatisticsService())->documentsByExtension()]);
    }

    public function metrics(Request $request): Response
    {
        $service = $request->query('service', '');
        if ($service !== '' && preg_match('/^[a-z0-9_.-]{1,64}$/iD', $service) !== 1) {
            throw HttpException::badRequest('Invalid service name');
        }
        // FIX: ?limit= was documented and sent by the UI but ignored.
        $limit = $request->queryInt('limit', 100, 1, 1000);

        return Response::json(['metrics' => (new SystemService())->recentMetrics($service, $limit)]);
    }

    public function collections(Request $request): Response
    {
        return Response::json(['collections' => (new MilvusClient())->listCollections()]);
    }

    /** @param array<string,string> $params */
    public function collectionStats(Request $request, array $params): Response
    {
        $name = (string) ($params['name'] ?? '');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,254}$/D', $name) !== 1) {
            throw HttpException::notFound('Collection not found');
        }

        return Response::json((new MilvusClient())->collectionStats($name));
    }
}
