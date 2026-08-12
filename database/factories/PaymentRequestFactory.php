<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Appropriation;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestBankDetails;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequest>
 */
final class PaymentRequestFactory extends Factory
{
    public const VALID_DIGITABLE_LINE = '23791234546789012345767890123457110000000012345';

    protected $model = PaymentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = number_format(fake()->randomFloat(2, 100, 10000), 2, '.', '');

        return [
            'branch_id' => Branch::factory(),
            'supplier_id' => Supplier::factory(),
            'cost_center_id' => CostCenter::factory(),
            'appropriation_id' => null,
            'payment_method' => PaymentMethod::Boleto,
            'status' => PaymentRequestStatus::Requested,
            'gross_amount' => $gross,
            'discount_amount' => '0.00',
            'net_amount' => $gross,
            'due_date' => fake()->dateTimeBetween('now', '+60 days'),
            'notes' => null,
            'has_attachments' => false,
        ];
    }

    public function requested(): static
    {
        return $this->state(fn (): array => ['status' => PaymentRequestStatus::Requested]);
    }

    public function launched(): static
    {
        return $this->state(fn (): array => ['status' => PaymentRequestStatus::Launched]);
    }

    public function settled(): static
    {
        return $this->state(fn (): array => ['status' => PaymentRequestStatus::Settled]);
    }

    public function boleto(): static
    {
        return $this
            ->state(fn (): array => ['payment_method' => PaymentMethod::Boleto])
            ->has(PaymentRequestBankDetailsFactory::new()->boleto(), 'bankDetails');
    }

    public function depositPix(): static
    {
        return $this
            ->state(fn (): array => ['payment_method' => PaymentMethod::Deposit])
            ->has(PaymentRequestBankDetailsFactory::new()->pix(), 'bankDetails');
    }

    public function depositPixQrCode(): static
    {
        return $this
            ->state(fn (): array => ['payment_method' => PaymentMethod::Deposit])
            ->has(PaymentRequestBankDetailsFactory::new()->pixQrCode(), 'bankDetails');
    }

    public function depositTransfer(): static
    {
        return $this
            ->state(fn (): array => ['payment_method' => PaymentMethod::Deposit])
            ->has(PaymentRequestBankDetailsFactory::new()->transfer(), 'bankDetails');
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => [
            'branch_id' => $branch->getKey(),
            'cost_center_id' => CostCenter::factory()->state(['branch_id' => $branch->getKey()]),
        ]);
    }

    public function forSupplier(Supplier $supplier): static
    {
        return $this->state(fn (): array => ['supplier_id' => $supplier->getKey()]);
    }

    public function withDiscount(string $discount): static
    {
        return $this->state(function (array $attributes) use ($discount): array {
            $gross = (string) $attributes['gross_amount'];

            return [
                'discount_amount' => $discount,
                'net_amount' => bcsub($gross, $discount, 2),
            ];
        });
    }

    public function withBoletoAttachment(): static
    {
        return $this->afterCreating(function (PaymentRequest $request): void {
            Attachment::factory()->boleto()->for($request, 'attachable')->create();
            $request->forceFill(['has_attachments' => true])->saveQuietly();
        });
    }

    public function awaitingApproval(): static
    {
        return $this->afterCreating(function (PaymentRequest $request): void {
            $approver = User::factory()->operador()->approver()->create();
            $rule = ApprovalRule::factory()
                ->forBranch($request->branch)
                ->forApprover($approver)
                ->range(0, null)
                ->create();

            Approval::factory()
                ->pending()
                ->forPaymentRequest($request)
                ->forApprover($approver)
                ->state(['approval_rule_id' => $rule->getKey()])
                ->create();
        });
    }

    public function returned(): static
    {
        return $this->afterCreating(function (PaymentRequest $request): void {
            $approver = User::factory()->operador()->approver()->create();

            Approval::factory()
                ->rejected()
                ->forPaymentRequest($request)
                ->forApprover($approver)
                ->create();
        });
    }

    public function approvedPendingLaunch(): static
    {
        return $this->afterCreating(function (PaymentRequest $request): void {
            $approver = User::factory()->adm()->approver()->create();

            Approval::factory()
                ->approved()
                ->forPaymentRequest($request)
                ->forApprover($approver)
                ->create();
        });
    }

    public function forCompanyRequiringAppropriation(): static
    {
        return $this->state(function (): array {
            $company = Company::factory()->requiresAppropriation()->create();
            $branch = Branch::factory()->for($company)->create();
            $appropriation = Appropriation::factory()->for($company)->create();
            $costCenter = CostCenter::factory()->for($branch)->create();

            return [
                'branch_id' => $branch->getKey(),
                'cost_center_id' => $costCenter->getKey(),
                'appropriation_id' => $appropriation->getKey(),
            ];
        });
    }
}
