<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRequestStatus;
use App\Models\Concerns\HasUuid;
use Database\Factories\PaymentRequestStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentRequestStatusHistory extends Model
{
    /** @use HasFactory<PaymentRequestStatusHistoryFactory> */
    use HasFactory, HasUuid;

    public $timestamps = false;

    public const UPDATED_AT = null;

    protected $table = 'payment_request_status_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_request_id',
        'from_status',
        'to_status',
        'changed_by',
        'notes',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => PaymentRequestStatus::class,
            'to_status' => PaymentRequestStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentRequest, $this>
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
