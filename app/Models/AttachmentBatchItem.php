<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentBatchDestinationType;
use App\Enums\AttachmentBatchItemStatus;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\AttachmentBatchItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AttachmentBatchItem extends Model
{
    /** @use HasFactory<AttachmentBatchItemFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'attachment_batch_id',
        'attachment_id',
        'sort_order',
        'status',
        'destination_type',
        'payment_request_id',
        'supplier_id',
        'operational_label',
        'classified_by',
        'classified_at',
        'rename_error',
        'renamed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttachmentBatchItemStatus::class,
            'destination_type' => AttachmentBatchDestinationType::class,
            'sort_order' => 'integer',
            'classified_at' => 'datetime',
            'renamed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AttachmentBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AttachmentBatch::class, 'attachment_batch_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function classifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by');
    }

    /**
     * @return HasMany<AttachmentBatchItemClassification, $this>
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(AttachmentBatchItemClassification::class);
    }
}
