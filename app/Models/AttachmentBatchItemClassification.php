<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentBatchDestinationType;
use App\Models\Concerns\HasUuid;
use Database\Factories\AttachmentBatchItemClassificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttachmentBatchItemClassification extends Model
{
    /** @use HasFactory<AttachmentBatchItemClassificationFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'attachment_batch_item_id',
        'user_id',
        'destination_type',
        'payment_request_id',
        'supplier_id',
        'operational_label',
        'classified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'destination_type' => AttachmentBatchDestinationType::class,
            'classified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttachmentBatchItem, $this>
     */
    public function attachmentBatchItem(): BelongsTo
    {
        return $this->belongsTo(AttachmentBatchItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaymentRequest, $this>
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
