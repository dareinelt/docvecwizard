<?php

declare(strict_types=1);

namespace App\Security;

final class CsrfException extends \RuntimeException
{
    public function __construct(string $message = 'CSRF token invalid')
    {
        parent::__construct($message);
    }
}
