<?php

declare(strict_types=1);

use App\Enums\PaymentRequestStatus;
use Tests\TestCase;

uses(TestCase::class);

it('uses expected case values', function (): void {
    expect(PaymentRequestStatus::Requested->value)->toBe('requested')
        ->and(PaymentRequestStatus::Launched->value)->toBe('launched')
        ->and(PaymentRequestStatus::Settled->value)->toBe('settled');
});

it('returns non-empty labels for all cases', function (PaymentRequestStatus $status): void {
    expect($status->getLabel())->not->toBeEmpty();
})->with(PaymentRequestStatus::cases());

it('returns valid colors for all cases', function (PaymentRequestStatus $status): void {
    expect($status->getColor())->toBeIn(['primary', 'info', 'danger', 'warning', 'success', 'gray']);
})->with(PaymentRequestStatus::cases());

it('allows only forward transitions', function (): void {
    expect(PaymentRequestStatus::Requested->canTransitionTo(PaymentRequestStatus::Launched))->toBeTrue()
        ->and(PaymentRequestStatus::Requested->canTransitionTo(PaymentRequestStatus::Settled))->toBeFalse()
        ->and(PaymentRequestStatus::Launched->canTransitionTo(PaymentRequestStatus::Settled))->toBeTrue()
        ->and(PaymentRequestStatus::Launched->canTransitionTo(PaymentRequestStatus::Requested))->toBeFalse()
        ->and(PaymentRequestStatus::Settled->canTransitionTo(PaymentRequestStatus::Launched))->toBeFalse()
        ->and(PaymentRequestStatus::Settled->canTransitionTo(PaymentRequestStatus::Requested))->toBeFalse();
});

it('returns allowed transitions', function (): void {
    expect(PaymentRequestStatus::Requested->allowedTransitions())->toBe([PaymentRequestStatus::Launched])
        ->and(PaymentRequestStatus::Launched->allowedTransitions())->toBe([PaymentRequestStatus::Settled])
        ->and(PaymentRequestStatus::Settled->allowedTransitions())->toBe([]);
});
