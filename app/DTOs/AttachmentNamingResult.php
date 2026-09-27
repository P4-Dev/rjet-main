<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AttachmentNamingResult
{
    public function __construct(
        public string $standardizedName,
        public string $path,
        public ?int $collisionSuffix = null,
    ) {}
}
