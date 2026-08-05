<?php

declare(strict_types=1);

namespace App\Integrations\Ocr;

use App\DTOs\BoletoOcrResult;
use App\Rules\ValidDigitableLine;
use Carbon\CarbonImmutable;
use Throwable;

final class LocalBoletoOcrClient implements BoletoOcrClient
{
    private const LEGACY_DUE_DATE_BASE = '1997-10-07';

    private const MODERN_DUE_DATE_BASE = '2025-02-22';

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

        return $this->extractFromText($text);
    }

    /**
     * Extract digitable line candidates from raw PDF text.
     *
     * Bank PDFs (e.g. Itaú) mix the digitable line with CNPJ, CEP, dates and
     * amounts. Stripping all non-digits from the whole document concatenates
     * those values and breaks naive 47/48-digit matching — so we collect
     * candidates from formatted patterns, per-line digits, then a sliding
     * window over the digit stream, validating each with check digits.
     */
    public function extractFromText(string $text): BoletoOcrResult
    {
        foreach ($this->digitableLineCandidates($text) as $candidate) {
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
     * @return list<string>
     */
    private function digitableLineCandidates(string $text): array
    {
        $candidates = [];

        if (preg_match_all(
            '/\d{5}\.?\d{5}\s+\d{5}\.?\d{6}\s+\d{5}\.?\d{6}\s+\d\s+\d{14}/',
            $text,
            $formatted,
        )) {
            foreach ($formatted[0] as $match) {
                $candidates[] = preg_replace('/\D/', '', $match) ?? '';
            }
        }

        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            $digits = preg_replace('/\D/', '', $line) ?? '';

            if (in_array(strlen($digits), [47, 48], true)) {
                $candidates[] = $digits;
            }
        }

        $normalized = preg_replace('/\D/', '', $text) ?? '';
        $length = strlen($normalized);

        foreach ([47, 48] as $size) {
            for ($offset = 0; $offset <= $length - $size; $offset++) {
                $candidates[] = substr($normalized, $offset, $size);
            }
        }

        return array_values(array_unique(array_filter($candidates)));
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

        return [$amount, $this->dueDateFromFactor($factor)];
    }

    /**
     * Resolve due date from the boleto factor.
     *
     * FEBRABAN reset the factor to 1000 on 2025-02-22 (legacy base was
     * 1997-10-07). Ambiguous factors are resolved by picking the candidate
     * closest to "today".
     */
    private function dueDateFromFactor(int $factor): ?CarbonImmutable
    {
        if ($factor <= 0) {
            return null;
        }

        $legacy = CarbonImmutable::parse(self::LEGACY_DUE_DATE_BASE)->addDays($factor);
        $candidates = [$legacy];

        if ($factor >= 1000) {
            $candidates[] = CarbonImmutable::parse(self::MODERN_DUE_DATE_BASE)->addDays($factor - 1000);
        }

        $now = CarbonImmutable::now()->startOfDay();

        usort(
            $candidates,
            fn (CarbonImmutable $left, CarbonImmutable $right): int => abs($left->diffInDays($now)) <=> abs($right->diffInDays($now)),
        );

        return $candidates[0];
    }
}
