<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasBlameable, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_request_id',
        'approval_rule_id',
        'approver_user_id',
        'status',
        'reason',
        'amount_snapshot',
        'branch_id_snapshot',
        'supplier_id_snapshot',
        'material_fingerprint',
        'assigned_at',
        'due_at',
        'decided_at',
        'decided_by',
        'escalated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'amount_snapshot' => 'decimal:2',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'decided_at' => 'datetime',
            'escalated_at' => 'datetime',
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
     * @return BelongsTo<ApprovalRule, $this>
     */
    public function approvalRule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<ApprovalReassignment, $this>
     */
    public function reassignments(): HasMany
    {
        return $this->hasMany(ApprovalReassignment::class);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending);
    }

    /**
     * Pending past due and not yet escalated. Excludes soft-deleted payment requests.
     *
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->pending()
            ->where('due_at', '<', now())
            ->whereNull('escalated_at')
            ->whereHas('paymentRequest');
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeForApprover(Builder $query, User $user): Builder
    {
        return $query->where('approver_user_id', $user->getKey());
    }

    /**
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeWithInactiveApprover(Builder $query): Builder
    {
        return $query->whereHas(
            'approver',
            fn (Builder $q): Builder => $q->where('is_active', false),
        );
    }
}
