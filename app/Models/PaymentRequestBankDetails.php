<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\DepositType;
use App\Enums\PixKeyType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\PaymentRequestBankDetailsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PaymentRequestBankDetails extends Model
{
    /** @use HasFactory<PaymentRequestBankDetailsFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_request_id',
        'deposit_type',
        'pix_key_type',
        'pix_key',
        'pix_qr_code',
        'digitable_line',
        'barcode',
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
     * @return BelongsTo<PaymentRequest, $this>
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function hasPixKey(): bool
    {
        return filled($this->pix_key_type) && filled($this->pix_key);
    }

    public function hasPixQrCode(): bool
    {
        return filled($this->pix_qr_code);
    }

    public function hasCompleteTransferData(): bool
    {
        return filled($this->holder_document)
            && filled($this->bank_id)
            && filled($this->agency)
            && filled($this->account_number)
            && filled($this->account_digit)
            && filled($this->account_type);
    }
}
