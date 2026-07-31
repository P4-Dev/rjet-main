<?php

declare(strict_types=1);

namespace App\Integrations\Ocr;

use App\DTOs\BoletoOcrResult;

interface BoletoOcrClient
{
    public function extract(string $absolutePath, string $mimeType): BoletoOcrResult;
}
