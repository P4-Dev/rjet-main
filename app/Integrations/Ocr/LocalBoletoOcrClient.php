<?php

declare(strict_types=1);

namespace App\Integrations\Ocr;

use App\DTOs\BoletoOcrResult;
use App\Rules\ValidDigitableLine;
use Carbon\CarbonImmutable;
use Throwable;

final class LocalBoletoOcrClient implements BoletoOcrClient
{
    public function extract(string $absolutePath, string $mimeType): BoletoOcrResult
    {
        if ($mimeType !== 'application/pdf') {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_image_not_supported'));
        }

        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'));
        }

        try {
            /** @var \Smalot\PdfParser\Parser $parser */
            $parser = new (\Smalot\PdfParser\Parser::class);
            $text = $parser->parseFile($absolutePath)->getText();
        } catch (Throwable) {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
        }

        $normalized = preg_replace('/[^\d]/', '', $text) ?? '';

        if (! preg_match_all('/\d{47,48}/', $normalized, $matches)) {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_not_found'));
        }

        foreach ($matches[0] as $candidate) {
            if (! ValidDigitableLine::isValid($candidate)) {
                continue;
            }

            if (strlen($candidate) === 47) {
                [$amount, $dueDate] = $this->extractAmountAndDueDate($candidate);

                return BoletoOcrResult::success($candidate, $amount, $dueDate);
            }

            return BoletoOcrResult::success($candidate, null, null);
        }

        return BoletoOcrResult::failed(__('payment_requests.messages.ocr_not_found'));
    }

    /**
     * @return array{0: ?string, 1: ?CarbonImmutable}
     */
    private function extractAmountAndDueDate(string $digitableLine): array
    {
        $barcode = ValidDigitableLine::toBarcode($digitableLine);

        if ($barcode === null) {
            return [null, null];
        }

        $factor = (int) substr($barcode, 5, 4);
        $rawAmount = substr($barcode, 9, 10);
        $amount = bcdiv($rawAmount, '100', 2);

        $dueDate = null;

        if ($factor > 0) {
            $dueDate = CarbonImmutable::parse('1997-10-07')->addDays($factor);
        }

        return [$amount, $dueDate];
    }
}
