<?php

declare(strict_types=1);

use App\Enums\ImportBatchStatus;
use Tests\TestCase;

uses(TestCase::class);

it('allows pending to processing and failed', function (): void {
    expect(ImportBatchStatus::Pending->canTransitionTo(ImportBatchStatus::Processing))->toBeTrue()
        ->and(ImportBatchStatus::Pending->canTransitionTo(ImportBatchStatus::Failed))->toBeTrue()
        ->and(ImportBatchStatus::Pending->canTransitionTo(ImportBatchStatus::Completed))->toBeFalse();
});

it('allows processing to completed and failed', function (): void {
    expect(ImportBatchStatus::Processing->canTransitionTo(ImportBatchStatus::Completed))->toBeTrue()
        ->and(ImportBatchStatus::Processing->canTransitionTo(ImportBatchStatus::Failed))->toBeTrue()
        ->and(ImportBatchStatus::Processing->canTransitionTo(ImportBatchStatus::Pending))->toBeFalse();
});

it('blocks transitions from terminal statuses', function (): void {
    expect(ImportBatchStatus::Completed->canTransitionTo(ImportBatchStatus::Failed))->toBeFalse()
        ->and(ImportBatchStatus::Failed->canTransitionTo(ImportBatchStatus::Processing))->toBeFalse()
        ->and(ImportBatchStatus::Completed->isTerminal())->toBeTrue()
        ->and(ImportBatchStatus::Failed->isTerminal())->toBeTrue()
        ->and(ImportBatchStatus::Pending->isTerminal())->toBeFalse();
});
