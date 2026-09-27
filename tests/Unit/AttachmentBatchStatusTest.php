<?php

declare(strict_types=1);

use App\Enums\AttachmentBatchStatus;
use Tests\TestCase;

uses(TestCase::class);

it('allows pending classification to classified and failed only', function (): void {
    expect(AttachmentBatchStatus::PendingClassification->canTransitionTo(AttachmentBatchStatus::Classified))->toBeTrue()
        ->and(AttachmentBatchStatus::PendingClassification->canTransitionTo(AttachmentBatchStatus::Failed))->toBeTrue()
        ->and(AttachmentBatchStatus::PendingClassification->canTransitionTo(AttachmentBatchStatus::Renaming))->toBeFalse()
        ->and(AttachmentBatchStatus::PendingClassification->canTransitionTo(AttachmentBatchStatus::Renamed))->toBeFalse();
});

it('allows classified only to renaming', function (): void {
    expect(AttachmentBatchStatus::Classified->canTransitionTo(AttachmentBatchStatus::Renaming))->toBeTrue()
        ->and(AttachmentBatchStatus::Classified->canTransitionTo(AttachmentBatchStatus::PendingClassification))->toBeFalse()
        ->and(AttachmentBatchStatus::Classified->canTransitionTo(AttachmentBatchStatus::Renamed))->toBeFalse();
});

it('allows renaming to every final outcome', function (): void {
    expect(AttachmentBatchStatus::Renaming->canTransitionTo(AttachmentBatchStatus::Renamed))->toBeTrue()
        ->and(AttachmentBatchStatus::Renaming->canTransitionTo(AttachmentBatchStatus::PartiallyFailed))->toBeTrue()
        ->and(AttachmentBatchStatus::Renaming->canTransitionTo(AttachmentBatchStatus::Failed))->toBeTrue()
        ->and(AttachmentBatchStatus::Renaming->canTransitionTo(AttachmentBatchStatus::Classified))->toBeFalse();
});

it('never reopens classification from terminal statuses', function (AttachmentBatchStatus $terminal): void {
    expect($terminal->isTerminal())->toBeTrue()
        ->and($terminal->canTransitionTo(AttachmentBatchStatus::PendingClassification))->toBeFalse()
        ->and($terminal->canTransitionTo(AttachmentBatchStatus::Classified))->toBeFalse();
})->with([
    AttachmentBatchStatus::Renamed,
    AttachmentBatchStatus::PartiallyFailed,
    AttachmentBatchStatus::Failed,
]);

it('only lets failed outcomes go back to renaming for retries', function (): void {
    expect(AttachmentBatchStatus::PartiallyFailed->canTransitionTo(AttachmentBatchStatus::Renaming))->toBeTrue()
        ->and(AttachmentBatchStatus::Failed->canTransitionTo(AttachmentBatchStatus::Renaming))->toBeTrue()
        ->and(AttachmentBatchStatus::Renamed->canTransitionTo(AttachmentBatchStatus::Renaming))->toBeFalse();
});
