<?php

declare(strict_types=1);

use App\DTOs\EligiblePaymentFilterData;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\PaymentRequest;
use App\Models\PaymentSettlement;
use App\Models\PaymentSettlementItem;
use App\Models\User;
use App\Services\PaymentSettlementService;
use Carbon\CarbonImmutable;

/**
 * @return list<string>
 */
function eligibleIds(EligiblePaymentFilterData $filter = new EligiblePaymentFilterData): array
{
    return app(PaymentSettlementService::class)
        ->eligibleQuery($filter, User::factory()->operador()->create())
        ->pluck('id')
        ->map(fn ($id): string => (string) $id)
        ->all();
}

it('returns only launched payment requests', function (): void {
    $launched = PaymentRequest::factory()->launched()->depositTransfer()->create();
    PaymentRequest::factory()->requested()->depositTransfer()->create();
    PaymentRequest::factory()->settled()->depositTransfer()->create();

    expect(eligibleIds())->toBe([(string) $launched->getKey()]);
});

it('filters by branch, due date and payment method', function (): void {
    $branch = Branch::factory()->create();
    $match = PaymentRequest::factory()->launched()->depositTransfer()->forBranch($branch)->create(['due_date' => '2026-10-10']);
    PaymentRequest::factory()->launched()->depositTransfer()->create(['due_date' => '2026-10-10']);
    PaymentRequest::factory()->launched()->depositTransfer()->forBranch($branch)->create(['due_date' => '2026-11-10']);
    PaymentRequest::factory()->launched()->boleto()->forBranch($branch)->create(['due_date' => '2026-10-10']);

    expect(eligibleIds(new EligiblePaymentFilterData(
        branchId: (string) $branch->getKey(),
        dueFrom: CarbonImmutable::parse('2026-10-01'),
        dueUntil: CarbonImmutable::parse('2026-10-31'),
        paymentMethod: PaymentMethod::Deposit,
    )))->toBe([(string) $match->getKey()]);
});

it('excludes trashed payment requests', function (): void {
    PaymentRequest::factory()->launched()->depositTransfer()->create()->delete();

    expect(eligibleIds())->toBe([]);
});

it('excludes payment requests with an active settlement item and includes released ones', function (): void {
    $settlement = PaymentSettlement::factory()->create();
    $active = PaymentSettlementItem::factory()->for($settlement, 'settlement')->create();
    $released = PaymentSettlementItem::factory()->for($settlement, 'settlement')->released()->create();

    expect(eligibleIds())
        ->not->toContain((string) $active->payment_request_id)
        ->toContain((string) $released->payment_request_id);
});
