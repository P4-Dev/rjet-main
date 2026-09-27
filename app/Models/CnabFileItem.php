<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CnabPaymentType;
use App\Models\Concerns\HasUuid;
use Database\Factories\CnabFileItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: created_at comes from the database default, there is no updated_at.
 */
final class CnabFileItem extends Model
{
    /** @use HasFactory<CnabFileItemFactory> */
    use HasFactory, HasUuid;

    public const UPDATED_AT = null;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cnab_file_id',
        'payment_settlement_item_id',
        'payment_type',
        'payment_form_code',
        'batch_number',
        'record_sequence',
        'reference',
        'amount',
        'is_valid',
        'validation_errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_type' => CnabPaymentType::class,
            'amount' => 'decimal:2',
            'is_valid' => 'boolean',
            'validation_errors' => 'array',
            'batch_number' => 'integer',
            'record_sequence' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CnabFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(CnabFile::class, 'cnab_file_id');
    }

    /**
     * @return BelongsTo<PaymentSettlementItem, $this>
     */
    public function settlementItem(): BelongsTo
    {
        return $this->belongsTo(PaymentSettlementItem::class, 'payment_settlement_item_id');
    }

    public static function referenceFor(string $paymentRequestId): string
    {
        return strtoupper(substr(str_replace('-', '', $paymentRequestId), -20));
    }
}
