<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRequestStatus;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use App\Observers\PaymentRequestObserver;
use Database\Factories\PaymentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(PaymentRequestObserver::class)]
final class PaymentRequest extends Model
{
    /** @use HasFactory<PaymentRequestFactory> */
    use HasAttachments, HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'supplier_id',
        'cost_center_id',
        'appropriation_id',
        'payment_method',
        'status',
        'gross_amount',
        'discount_amount',
        'net_amount',
        'due_date',
        'notes',
        'has_attachments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => PaymentRequestStatus::class,
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'due_date' => 'date',
            'has_attachments' => 'boolean',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<CostCenter, $this>
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /**
     * @return BelongsTo<Appropriation, $this>
     */
    public function appropriation(): BelongsTo
    {
        return $this->belongsTo(Appropriation::class);
    }

    /**
     * @return HasOne<PaymentRequestBankDetails, $this>
     */
    public function bankDetails(): HasOne
    {
        return $this->hasOne(PaymentRequestBankDetails::class);
    }

    /**
     * @return HasMany<PaymentRequestStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(PaymentRequestStatusHistory::class);
    }

    public function company(): ?Company
    {
        return $this->branch?->company;
    }

    public function requiresAppropriation(): bool
    {
        return $this->company()?->is_appropriation_required ?? false;
    }

    public function isEditableBy(User $user): bool
    {
        if ($user->isAdm()) {
            return true;
        }

        if ($user->isOperador()) {
            return in_array($this->status, [
                PaymentRequestStatus::Requested,
                PaymentRequestStatus::Launched,
            ], true);
        }

        return $this->status === PaymentRequestStatus::Requested
            && $user->branches()->whereKey($this->branch_id)->exists();
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->role->seesAllBranches()
            || $user->branches()->whereKey($this->branch_id)->exists();
    }

    public static function requiresAppropriationForBranch(?string $branchId): bool
    {
        if (blank($branchId)) {
            return false;
        }

        /** @var array<string, bool> $cache */
        static $cache = [];

        if (! array_key_exists($branchId, $cache)) {
            $cache[$branchId] = Branch::query()->with('company')->find($branchId)?->company?->is_appropriation_required ?? false;
        }

        return $cache[$branchId];
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllBranches()) {
            return $query;
        }

        return $query->whereHas(
            'branch.users',
            fn (Builder $q): Builder => $q->whereKey($user->getKey()),
        );
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeStatus(Builder $query, PaymentRequestStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeDueBetween(Builder $query, ?string $from, ?string $until): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '>=', $date))
            ->when($until, fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '<=', $date));
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeForBranch(Builder $query, Branch|string $branch): Builder
    {
        return $query->where(
            'branch_id',
            $branch instanceof Branch ? $branch->getKey() : $branch,
        );
    }
}
