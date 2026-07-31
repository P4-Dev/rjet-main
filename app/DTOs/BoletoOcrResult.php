<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class BoletoOcrResult
{
    public function __construct(
        public bool $wasSuccessful,
        public ?string $digitableLine = null,
        public ?string $amount = null,
        public ?CarbonImmutable $dueDate = null,
        public ?string $message = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self(
            wasSuccessful: false,
            message: $message,
        );
    }

    public static function success(?string $digitableLine, ?string $amount, ?CarbonImmutable $dueDate): self
    {
        return new self(
            wasSuccessful: true,
            digitableLine: $digitableLine,
            amount: $amount,
            dueDate: $dueDate,
        );
    }
}
