<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRequestStatusHistory>
 */
final class PaymentRequestStatusHistoryFactory extends Factory
{
    protected $model = PaymentRequestStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_request_id' => PaymentRequest::factory(),
            'from_status' => null,
            'to_status' => PaymentRequestStatus::Requested,
            'changed_by' => User::factory(),
            'notes' => null,
            'created_at' => now(),
        ];
    }
}
