<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentBatchDestinationType;
use App\Models\AttachmentBatchItem;
use App\Models\AttachmentBatchItemClassification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttachmentBatchItemClassification>
 */
final class AttachmentBatchItemClassificationFactory extends Factory
{
    protected $model = AttachmentBatchItemClassification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachment_batch_item_id' => AttachmentBatchItem::factory(),
            'user_id' => User::factory(),
            'destination_type' => AttachmentBatchDestinationType::OperationalCategory,
            'payment_request_id' => null,
            'supplier_id' => null,
            'operational_label' => 'Despesas gerais',
            'classified_at' => now(),
        ];
    }
}
