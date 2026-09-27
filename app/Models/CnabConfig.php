<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CnabLayout;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\CnabConfigFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CnabConfig extends Model
{
    /** @use HasFactory<CnabConfigFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    public const MAX_FILE_SEQUENCE = 999999;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_bank_account_id',
        'layout',
        'company_name',
        'agreement_code',
        'wallet_code',
        'payment_type_code',
        'last_file_sequence',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => CnabLayout::class,
            'is_active' => 'boolean',
            'last_file_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BranchBankAccount, $this>
     */
    public function branchBankAccount(): BelongsTo
    {
        return $this->belongsTo(BranchBankAccount::class)->withTrashed();
    }

    /**
     * @return HasMany<CnabFile, $this>
     */
    public function cnabFiles(): HasMany
    {
        return $this->hasMany(CnabFile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function branch(): ?Branch
    {
        return $this->branchBankAccount?->branch;
    }

    public function bank(): ?Bank
    {
        return $this->branchBankAccount?->bank;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAccount(Builder $query, BranchBankAccount|string $account): Builder
    {
        return $query->where(
            'branch_bank_account_id',
            $account instanceof BranchBankAccount ? $account->getKey() : $account,
        );
    }

    public function hasIssuedFiles(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->cnabFiles()->withTrashed()->whereNotNull('file_sequence')->exists();
    }
}
