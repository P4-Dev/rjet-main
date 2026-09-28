<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;
use Illuminate\Support\Carbon;

final class PaymentRequestObserver
{
    /**
     * @var array<string, Carbon|null>
     */
    private array $restoreThresholds = [];

    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function updated(PaymentRequest $paymentRequest): void
    {
        if ($paymentRequest->wasChanged(['due_date', 'net_amount', 'cost_center_id', 'branch_id'])) {
            $this->metrics->flush();
        }
    }

    public function deleted(PaymentRequest $paymentRequest): void
    {
        $paymentRequest->bankDetails()->delete();
        $paymentRequest->attachments()->delete();

        $this->metrics->flush();
    }

    public function restoring(PaymentRequest $paymentRequest): void
    {
        $this->restoreThresholds[(string) $paymentRequest->getKey()] = $paymentRequest->deleted_at;
    }

    public function restored(PaymentRequest $paymentRequest): void
    {
        $threshold = $this->restoreThresholds[(string) $paymentRequest->getKey()] ?? $paymentRequest->updated_at;

        $paymentRequest->bankDetails()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        $paymentRequest->attachments()->onlyTrashed()
            ->where('deleted_at', '>=', $threshold)
            ->restore();

        unset($this->restoreThresholds[(string) $paymentRequest->getKey()]);

        $this->metrics->flush();
    }

    public function forceDeleting(PaymentRequest $paymentRequest): void
    {
        Attachment::withTrashed()
            ->where('attachable_type', $paymentRequest->getMorphClass())
            ->where('attachable_id', $paymentRequest->getKey())
            ->get()
            ->each(fn (Attachment $attachment): bool => $attachment->forceDelete());

        $paymentRequest->bankDetails()->withTrashed()->forceDelete();
    }

    public function forceDeleted(PaymentRequest $paymentRequest): void
    {
        $this->metrics->flush();
    }
}
