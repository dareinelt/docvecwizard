<?php

declare(strict_types=1);

namespace App\Domain;

enum JobStatus: string
{
    case Created = 'CREATED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Paused = 'PAUSED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
