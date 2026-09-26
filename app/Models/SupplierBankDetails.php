<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PixKeyType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\SupplierBankDetailsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SupplierBankDetails extends Model
{
    /** @use HasFactory<SupplierBankDetailsFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_id',
        'deposit_type',
        'pix_key_type',
        'pix_key',
        'bank_id',
        'agency',
        'agency_digit',
        'account_number',
        'account_digit',
        'account_type',
        'holder_name',
        'holder_document',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deposit_type' => DepositType::class,
            'pix_key_type' => PixKeyType::class,
            'account_type' => AccountType::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPaymentRequestFormState(): array
    {
        return [
            'deposit_type' => $this->deposit_type?->value,
            'pix_key_type' => $this->pix_key_type?->value,
            'pix_key' => $this->pix_key,
            'holder_document' => $this->holder_document,
            'holder_name' => $this->holder_name,
            'bank_id' => $this->bank_id,
            'agency' => $this->agency,
            'agency_digit' => $this->agency_digit,
            'account_number' => $this->account_number,
            'account_digit' => $this->account_digit,
            'account_type' => $this->account_type?->value,
        ];
    }
}
