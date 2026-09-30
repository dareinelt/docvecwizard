<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function channel(string $service): LoggerInstance
    {
        return new LoggerInstance($service);
    }
}

final class LoggerInstance
{
    private const LEVEL_MAP = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4];

    private int $threshold;

    public function __construct(private readonly string $service)
    {
        $level = strtoupper(Config::string('LOG_LEVEL', 'INFO'));
        $this->threshold = self::LEVEL_MAP[$level] ?? 1;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->write('CRITICAL', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if ((self::LEVEL_MAP[$level] ?? 1) < $this->threshold) {
            return;
        }
        $entry = array_merge([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'service' => $this->service,
            'message' => $message,
        ], $context);
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode(['level' => $level, 'service' => $this->service, 'message' => $message]);
        }
        error_log((string) $json);
    }
}
