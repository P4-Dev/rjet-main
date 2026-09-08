<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use App\Observers\BankObserver;
use Database\Factories\BankFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(BankObserver::class)]
final class Bank extends Model
{
    /** @use HasFactory<BankFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'ispb',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BranchBankAccount, $this>
     */
    public function branchBankAccounts(): HasMany
    {
        return $this->hasMany(BranchBankAccount::class);
    }

    /**
     * @return HasMany<PaymentRequestBankDetails, $this>
     */
    public function paymentRequestBankDetails(): HasMany
    {
        return $this->hasMany(PaymentRequestBankDetails::class);
    }

    /**
     * @return HasMany<SupplierBankDetails, $this>
     */
    public function supplierBankDetails(): HasMany
    {
        return $this->hasMany(SupplierBankDetails::class);
    }

    /**
     * @param  Builder<Bank>  $query
     * @return Builder<Bank>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
