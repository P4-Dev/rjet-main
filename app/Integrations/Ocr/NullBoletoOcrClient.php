<?php

declare(strict_types=1);

namespace App\Integrations\Ocr;

use App\DTOs\BoletoOcrResult;

final class NullBoletoOcrClient implements BoletoOcrClient
{
    public function extract(string $absolutePath, string $mimeType): BoletoOcrResult
    {
        return BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'));
    }
}
