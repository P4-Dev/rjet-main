<?php

declare(strict_types=1);

use App\Integrations\Ocr\LocalBoletoOcrClient;
use Database\Factories\PaymentRequestFactory;
use Tests\TestCase;

uses(TestCase::class);

it('extracts a formatted digitable line from noisy itau boleto text', function (): void {
    $text = <<<'TXT'
341-7

34191.09610 51885.608292 01095.970008 9 14530000111105
Beneficiário
   MC LOCADORA PETROLINA LTDA
Agência/Código do Beneficiário
   8290 / 10959-7
CPF/CNPJ
   06890020000161
Vencimento
   21/05/2026
Valor documento
1.111,05
Pagador
    LOCALIZA FLEET S.A 02286479000108
Endereço: Avenida Bernardo de Vasconcelos 377 - CEP: 31150900

341-7

34191.09610 51885.608292 01095.970008 9 14530000111105
TXT;

    $result = (new LocalBoletoOcrClient)->extractFromText($text);

    expect($result->wasSuccessful)->toBeTrue()
        ->and($result->digitableLine)->toBe('34191096105188560829201095970008914530000111105')
        ->and($result->amount)->toBe('1111.05')
        ->and($result->dueDate?->toDateString())->toBe('2026-05-21');
});

it('still finds a digitable line when digits are concatenated with surrounding fields', function (): void {
    $line = '34191096105188560829201095970008914530000111105';
    // Simulate the broken normalization: bank header "3417" prepended + CNPJ noise appended.
    $noisyDigits = '3417'.$line.'0689002000016102286479000108';

    $result = (new LocalBoletoOcrClient)->extractFromText($noisyDigits);

    expect($result->wasSuccessful)->toBeTrue()
        ->and($result->digitableLine)->toBe($line)
        ->and($result->amount)->toBe('1111.05');
});

it('extracts amount and due date from the factory digitable line', function (): void {
    $result = (new LocalBoletoOcrClient)->extractFromText(PaymentRequestFactory::VALID_DIGITABLE_LINE);

    expect($result->wasSuccessful)->toBeTrue()
        ->and($result->digitableLine)->toBe(PaymentRequestFactory::VALID_DIGITABLE_LINE)
        ->and($result->amount)->toBe('123.45')
        ->and($result->dueDate)->not->toBeNull();
});

it('returns failed when no valid digitable line exists', function (): void {
    $result = (new LocalBoletoOcrClient)->extractFromText('Boleto sem linha digitável. Valor 1.111,05 CNPJ 06890020000161');

    expect($result->wasSuccessful)->toBeFalse()
        ->and($result->message)->not->toBeEmpty();
});
