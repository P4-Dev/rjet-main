<?php

declare(strict_types=1);

namespace App\Listeners\Dashboard;

use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Services\DashboardMetricsService;

final class FlushDashboardMetricsCache
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function handlePaymentRequestCreated(PaymentRequestCreated $event): void
    {
        $this->metrics->flush();
    }

    public function handlePaymentRequestStatusChanged(PaymentRequestStatusChanged $event): void
    {
        $this->metrics->flush();
    }

    public function handlePaymentRequestBatchImported(PaymentRequestBatchImported $event): void
    {
        $this->metrics->flush();
    }
}
