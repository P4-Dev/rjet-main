<?php

declare(strict_types=1);

use App\Enums\ApprovalStatus;
use Tests\TestCase;

uses(TestCase::class);

it('allows pending to approved and rejected', function (): void {
    expect(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Approved))->toBeTrue()
        ->and(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Rejected))->toBeTrue()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Rejected))->toBeFalse()
        ->and(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Approved))->toBeFalse()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Pending))->toBeFalse();
});

it('returns labels and colors', function (ApprovalStatus $status): void {
    expect($status->getLabel())->not->toBeEmpty()
        ->and($status->getColor())->toBeIn(['warning', 'success', 'danger'])
        ->and($status->getIcon())->not->toBeEmpty();
})->with(ApprovalStatus::cases());
