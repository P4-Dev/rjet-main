<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CnabFileStatus;
use App\Enums\PaymentSettlementStatus;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\PaymentSettlementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PaymentSettlement extends Model
{
    /** @use HasFactory<PaymentSettlementFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    public const TIMEZONE = 'America/Sao_Paulo';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'branch_bank_account_id',
        'status',
        'settlement_date',
        'items_count',
        'total_amount',
        'notes',
        'settled_at',
        'settled_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentSettlementStatus::class,
            'settlement_date' => 'date',
            'total_amount' => 'decimal:2',
            'items_count' => 'integer',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<BranchBankAccount, $this>
     */
    public function branchBankAccount(): BelongsTo
    {
        return $this->belongsTo(BranchBankAccount::class)->withTrashed();
    }

    /**
     * @return HasMany<PaymentSettlementItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaymentSettlementItem::class);
    }

    /**
     * @return HasMany<PaymentSettlementItem, $this>
     */
    public function activeItems(): HasMany
    {
        return $this->hasMany(PaymentSettlementItem::class)->whereNull('released_at');
    }

    /**
     * @return HasMany<CnabFile, $this>
     */
    public function cnabFiles(): HasMany
    {
        return $this->hasMany(CnabFile::class);
    }

    /**
     * At most one active file exists per settlement (partial unique index).
     *
     * @return HasOne<CnabFile, $this>
     */
    public function currentCnabFile(): HasOne
    {
        return $this->hasOne(CnabFile::class)
            ->whereIn('status', CnabFileStatus::activeValues())
            ->latest('created_at');
    }

    /**
     * Ordered HasOne instead of latestOfMany(): MAX() is not defined for uuid keys on PostgreSQL.
     *
     * @return HasOne<CnabFile, $this>
     */
    public function latestCnabFile(): HasOne
    {
        return $this->hasOne(CnabFile::class)
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isOperador() || $user->isAdm()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStatus(Builder $query, PaymentSettlementStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, Branch|string $branch): Builder
    {
        return $query->where('branch_id', $branch instanceof Branch ? $branch->getKey() : $branch);
    }

    public function isDraft(): bool
    {
        return $this->status === PaymentSettlementStatus::Draft;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function hasActiveCnabGeneration(): bool
    {
        return $this->cnabFiles()->whereIn('status', CnabFileStatus::inProgressValues())->exists();
    }

    public function hasGeneratedCnabFile(): bool
    {
        return $this->cnabFiles()->where('status', CnabFileStatus::Generated)->exists();
    }

    /**
     * Live and active configuration of the paying account, if any.
     */
    public function cnabConfig(): ?CnabConfig
    {
        /** @var CnabConfig|null $config */
        $config = CnabConfig::query()
            ->active()
            ->forAccount((string) $this->branch_bank_account_id)
            ->first();

        return $config;
    }

    public function isSettlementDateFuture(): bool
    {
        return $this->settlement_date->toDateString() > today(self::TIMEZONE)->toDateString();
    }

    public function isSettlementDatePast(): bool
    {
        return $this->settlement_date->toDateString() < today(self::TIMEZONE)->toDateString();
    }
}
