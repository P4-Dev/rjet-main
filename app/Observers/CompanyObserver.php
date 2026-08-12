<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Attachment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentRequest;

final class CompanyObserver
{
    /**
     * Soft-delete threshold captured per company for cascade restore.
     *
     * @var array<string, \Illuminate\Support\Carbon|null>
     */
    private array $restoreThresholds = [];

    /**
     * Soft-delete order:
     * 1) payment requests (Eloquent — cascade bankDetails + attachments)
     * 2) supplier payment overrides
     * 3) appropriations
     * 4) per branch: cost centers, then bank accounts
     * 5) branches
     */
    public function deleted(Company $company): void
    {
        $branchIds = $company->branches()->pluck('id');

        PaymentRequest::query()
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->each->delete();

        $company->supplierPaymentMethods()->delete();
        $company->appropriations()->delete();

        $company->branches()->get()->each(function (Branch $branch): void {
            $branch->approvalRules()->delete();
            $branch->costCenters()->delete();
            $branch->bankAccounts()->delete();
        });

        $company->branches()->delete();
    }

    public function restoring(Company $company): void
    {
        $this->restoreThresholds[(string) $company->getKey()] = $company->deleted_at;
    }

    public function restored(Company $company): void
    {
        $threshold = $this->restoreThresholds[(string) $company->getKey()] ?? $company->updated_at;
        $branchIds = $company->branches()->withTrashed()->pluck('id');

        PaymentRequest::onlyTrashed()
            ->whereIn('branch_id', $branchIds)
            ->where('deleted_at', '>=', $threshold)
            ->get()
            ->each->restore();

        $company->supplierPaymentMethods()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $company->appropriations()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $company->branches()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->get()
            ->each(function (Branch $branch) use ($threshold): void {
                $branch->restore();

                $branch->approvalRules()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();

                $branch->costCenters()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();

                $branch->bankAccounts()->onlyTrashed()
                    ->where('deleted_at', '>=', $threshold)
                    ->restore();
            });

        unset($this->restoreThresholds[(string) $company->getKey()]);
    }

    /**
     * Must run BEFORE the company row is hard-deleted: FK cascade would otherwise
     * wipe branches/payment_requests without Eloquent events, orphaning morph attachments.
     */
    public function forceDeleting(Company $company): void
    {
        $branchIds = Branch::withTrashed()
            ->where('company_id', $company->getKey())
            ->pluck('id');

        PaymentRequest::withTrashed()
            ->whereIn('branch_id', $branchIds)
            ->get()
            ->each(function (PaymentRequest $paymentRequest): void {
                Attachment::withTrashed()
                    ->where('attachable_type', $paymentRequest->getMorphClass())
                    ->where('attachable_id', $paymentRequest->getKey())
                    ->get()
                    ->each(fn (Attachment $attachment): bool => $attachment->forceDelete());

                $paymentRequest->forceDelete();
            });

        $company->supplierPaymentMethods()->withTrashed()->forceDelete();
        $company->appropriations()->withTrashed()->forceDelete();

        Branch::withTrashed()
            ->where('company_id', $company->getKey())
            ->get()
            ->each(function (Branch $branch): void {
                $branch->approvalRules()->withTrashed()->forceDelete();
                $branch->costCenters()->withTrashed()->forceDelete();
                $branch->bankAccounts()->withTrashed()->forceDelete();
                $branch->forceDelete();
            });
    }
}
