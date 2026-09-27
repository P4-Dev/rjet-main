<?php

declare(strict_types=1);

use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;

it('resolves attachment batch item status labels via translation', function (): void {
    expect(AttachmentBatchItemStatus::Pending->getLabel())->toBe('Pendente')
        ->and(AttachmentBatchItemStatus::Classified->getLabel())->toBe('Classificado')
        ->and(AttachmentBatchItemStatus::Renamed->getLabel())->toBe('Renomeado')
        ->and(AttachmentBatchItemStatus::Failed->getLabel())->toBe('Falhou')
        ->and(AttachmentBatchItemStatus::Failed->getColor())->toBe('danger')
        ->and(AttachmentBatchItemStatus::Renamed->getColor())->toBe('success');
});

it('resolves attachment batch destination type labels via translation', function (): void {
    expect(AttachmentBatchDestinationType::PaymentRequest->getLabel())->toBe('Solicitação de pagamento')
        ->and(AttachmentBatchDestinationType::Supplier->getLabel())->toBe('Fornecedor')
        ->and(AttachmentBatchDestinationType::OperationalCategory->getLabel())->toBe('Categoria operacional');
});

it('translates every attachment batch enum case in both locales with color and icon', function (string $locale): void {
    app()->setLocale($locale);

    $cases = [
        ...AttachmentBatchStatus::cases(),
        ...AttachmentBatchItemStatus::cases(),
        ...AttachmentBatchDestinationType::cases(),
    ];

    foreach ($cases as $case) {
        expect($case->getLabel())->toBeString()->not->toStartWith('enums.')
            ->and($case->getColor())->toBeString()->not->toBeEmpty()
            ->and($case->getIcon())->toBeString()->toStartWith('heroicon-');
    }
})->with(['pt_BR', 'en']);
