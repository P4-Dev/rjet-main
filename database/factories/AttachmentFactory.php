<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
final class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attachable_type' => (new PaymentRequest)->getMorphClass(),
            'attachable_id' => PaymentRequest::factory(),
            'type' => AttachmentType::Other,
            'disk' => 'local',
            'path' => 'attachments/payment_request/'.fake()->uuid().'/'.fake()->uuid().'.pdf',
            'original_name' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 500000),
            'sort_order' => 0,
        ];
    }

    public function boleto(): static
    {
        return $this->state(fn (): array => [
            'type' => AttachmentType::Boleto,
            'mime_type' => 'application/pdf',
            'original_name' => 'boleto.pdf',
        ]);
    }

    public function image(): static
    {
        return $this->state(fn (): array => [
            'type' => AttachmentType::Other,
            'mime_type' => 'image/png',
            'original_name' => 'comprovante.png',
            'path' => 'attachments/payment_request/'.fake()->uuid().'/'.fake()->uuid().'.png',
        ]);
    }
}
