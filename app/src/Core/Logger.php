<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logger factory. LoggerInstance lives in its own file so the PSR-4 style
 * autoloader can resolve it independently (previously both classes shared one
 * file and LoggerInstance was only loadable after Logger had been touched).
 */
final class Logger
{
    public static function channel(string $service): LoggerInstance
    {
        return new LoggerInstance($service);
    }
}
