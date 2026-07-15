<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use App\Observers\BranchBankAccountObserver;
use Database\Factories\BranchBankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(BranchBankAccountObserver::class)]
final class BranchBankAccount extends Model
{
    /** @use HasFactory<BranchBankAccountFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'bank_id',
        'bank_code',
        'bank_name',
        'agency',
        'agency_digit',
        'account_number',
        'account_digit',
        'account_type',
        'holder_name',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<BranchBankAccount>  $query
     * @return Builder<BranchBankAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<BranchBankAccount>  $query
     * @return Builder<BranchBankAccount>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
