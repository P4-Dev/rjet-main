<?php

declare(strict_types=1);

use App\DTOs\CnabRemittanceData;
use App\DTOs\CnabRemittanceResult;
use App\DTOs\CnabValidationError;
use App\Integrations\Cnab\CnabRemittanceValidator;
use App\Integrations\Cnab\Itau\Itau240RemittanceAdapter;

/**
 * @return array{CnabRemittanceData, CnabRemittanceResult, list<string>}
 */
function validRemittance(): array
{
    $data = makeCnabRemittanceData([
        makeCnabItemData('ted', 1),
        makeCnabItemData('ted', 2, '99.90'),
        makeCnabItemData('pix_key', 3),
    ]);
    $result = (new Itau240RemittanceAdapter)->build($data);

    return [$data, $result, explode("\r\n", rtrim($result->content, "\r\n"))];
}

/**
 * @param  list<string>  $lines
 * @return list<string>
 */
function validationCodes(array $lines, CnabRemittanceData $data, CnabRemittanceResult $result): array
{
    $errors = (new CnabRemittanceValidator)->validate(implode("\r\n", $lines)."\r\n", $data, $result);

    return array_map(fn (CnabValidationError $error): string => $error->code, $errors);
}

it('accepts a well formed remittance', function (): void {
    [$data, $result, $lines] = validRemittance();

    expect(validationCodes($lines, $data, $result))->toBe([]);
});

it('rejects lines that are not 240 characters long', function (): void {
    [$data, $result, $lines] = validRemittance();
    $lines[2] = substr($lines[2], 0, 239);

    expect(validationCodes($lines, $data, $result))->toBe(['line_length_invalid']);
});

it('rejects non ascii characters', function (): void {
    [$data, $result, $lines] = validRemittance();
    $lines[2] = substr($lines[2], 0, 100)."\x07".substr($lines[2], 101);

    expect(validationCodes($lines, $data, $result))->toContain('non_ascii_character');
});

it('rejects records out of order', function (): void {
    [$data, $result, $lines] = validRemittance();
    [$lines[1], $lines[2]] = [$lines[2], $lines[1]];

    expect(validationCodes($lines, $data, $result))->toContain('record_order_invalid');
});

it('rejects gaps in the detail sequence', function (): void {
    [$data, $result, $lines] = validRemittance();
    $lines[3] = substr($lines[3], 0, 8).'00009'.substr($lines[3], 13);

    expect(validationCodes($lines, $data, $result))->toBe(['sequence_gap']);
});

it('rejects a batch trailer with a divergent record count', function (): void {
    [$data, $result, $lines] = validRemittance();
    $trailerIndex = firstLineOfType($lines, '5');
    $lines[$trailerIndex] = substr($lines[$trailerIndex], 0, 17).'000099'.substr($lines[$trailerIndex], 23);

    expect(validationCodes($lines, $data, $result))->toContain('batch_trailer_mismatch');
});

it('rejects a file trailer with a divergent record count', function (): void {
    [$data, $result, $lines] = validRemittance();
    $last = count($lines) - 1;
    $lines[$last] = substr($lines[$last], 0, 23).'000099'.substr($lines[$last], 29);

    expect(validationCodes($lines, $data, $result))->toBe(['file_trailer_mismatch']);
});

it('rejects a divergent amount sum', function (): void {
    [$data, $result, $lines] = validRemittance();
    $trailerIndex = firstLineOfType($lines, '5');
    $lines[$trailerIndex] = substr($lines[$trailerIndex], 0, 23).str_pad('1', 18, '0', STR_PAD_LEFT).substr($lines[$trailerIndex], 41);

    expect(validationCodes($lines, $data, $result))->toContain('batch_trailer_mismatch', 'total_amount_mismatch');
});

/**
 * @param  list<string>  $lines
 */
function firstLineOfType(array $lines, string $recordType): int
{
    foreach ($lines as $index => $line) {
        if ($line[7] === $recordType) {
            return $index;
        }
    }

    throw new RuntimeException("No record of type {$recordType}.");
}
