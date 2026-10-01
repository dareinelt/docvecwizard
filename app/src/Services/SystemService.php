<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Http\JsonClient;

final class SystemService
{
    /** @return array<string,mixed> */
    public function info(): array
    {
        return [
            'app' => [
                'version' => Config::string('APP_VERSION', '0.0.0'),
                'timezone' => Config::string('APP_TIMEZONE', 'UTC'),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
            ],
            'storage' => [
                'input_root' => Config::string('INPUT_ROOT', '/srv/data/input'),
                'staging_root' => Config::string('STAGING_ROOT', '/srv/data/staging'),
                'export_root' => Config::string('EXPORT_ROOT', '/srv/data/exports'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $checks = [
            'database' => $this->database(),
            'embedding' => $this->embedding(),
            'converter' => $this->converter(),
            'milvus' => (new MilvusClient())->health(),
        ];
        $healthy = !in_array(false, $checks, true);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    private function database(): bool
    {
        try {
            return (int) Db::fetchValue('SELECT 1') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function embedding(): bool
    {
        try {
            $health = (new EmbeddingClient())->health();

            // The service reports "degraded" (and model_loaded=false) when no
            // model is loaded, e.g. because it was never downloaded.
            return ($health['status'] ?? '') === 'ok' && ($health['model_loaded'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function converter(): bool
    {
        try {
            return (new ConverterClient())->health()['status'] === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Record a system metric snapshot. */
    public function recordMetric(string $service, string $metric, float $value, array $meta = []): void
    {
        Db::execute(
            'INSERT INTO system_metrics (service, metric, value, meta) VALUES (?, ?, ?, ?)',
            [$service, $metric, $value, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentMetrics(string $service = '', int $limit = 100): array
    {
        $sql = 'SELECT * FROM system_metrics';
        $params = [];
        if ($service !== '') {
            $sql .= ' WHERE service = ?';
            $params[] = $service;
        }
        $sql .= ' ORDER BY recorded_at DESC LIMIT ?';
        $params[] = $limit;

        return Db::fetchAll($sql, $params);
    }
}
