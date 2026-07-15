<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

class BusinessException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $userMessage = null,
        int $code = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getUserMessage(): string
    {
        return $this->userMessage ?? __('errors.generic');
    }
}
