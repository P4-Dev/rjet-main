<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\AttachmentType;
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

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function company(): ?Company
    {
        return $this->branch?->company;
    }

    public function requiresAppropriation(): bool
    {
        return $this->company()?->is_appropriation_required ?? false;
    }

    public function currentPendingApproval(): ?Approval
    {
        if ($this->relationLoaded('approvals')) {
            /** @var Approval|null $approval */
            $approval = $this->approvals
                ->filter(fn (Approval $item): bool => $item->status === ApprovalStatus::Pending)
                ->sortByDesc(fn (Approval $item): int => $item->assigned_at?->getTimestamp() ?? 0)
                ->first();

            return $approval;
        }

        /** @var Approval|null $approval */
        $approval = $this->approvals()
            ->where('status', ApprovalStatus::Pending)
            ->latest('assigned_at')
            ->first();

        return $approval;
    }

    public function latestApproval(): ?Approval
    {
        if ($this->relationLoaded('approvals')) {
            /** @var Approval|null $approval */
            $approval = $this->approvals
                ->sortBy([
                    fn (Approval $item): int => $item->assigned_at?->getTimestamp() ?? 0,
                    fn (Approval $item): int => $item->created_at?->getTimestamp() ?? 0,
                ])
                ->last();

            return $approval;
        }

        /** @var Approval|null $approval */
        $approval = $this->approvals()
            ->latest('assigned_at')
            ->latest('created_at')
            ->first();

        return $approval;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->currentPendingApproval() !== null;
    }

    public function isReturnedToRequester(): bool
    {
        $latest = $this->latestApproval();

        return $this->status === PaymentRequestStatus::Requested
            && $latest?->status === ApprovalStatus::Rejected
            && $this->currentPendingApproval() === null;
    }

    public function hasApprovedForLaunch(): bool
    {
        $latest = $this->latestApproval();

        return $latest !== null
            && $latest->status === ApprovalStatus::Approved
            && $latest->material_fingerprint === $this->currentMaterialFingerprint();
    }

    /**
     * SHA-256 of material fields used as the launch gate fingerprint.
     */
    public function currentMaterialFingerprint(): string
    {
        $this->loadMissing(['bankDetails', 'attachments']);

        $payload = [
            'net_amount' => number_format((float) $this->net_amount, 2, '.', ''),
            'branch_id' => (string) $this->branch_id,
            'supplier_id' => (string) $this->supplier_id,
            'payment_method' => $this->payment_method instanceof PaymentMethod
                ? $this->payment_method->value
                : (string) $this->payment_method,
            'bank_details' => $this->fingerprintBankDetails(),
            'boleto_attachments' => $this->fingerprintBoletoAttachments(),
        ];

        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Virtual UI helper: awaiting | returned | approved_ready | no_rule | none
     */
    public function approvalState(): string
    {
        if ($this->isAwaitingApproval()) {
            return 'awaiting';
        }

        if ($this->isReturnedToRequester()) {
            return 'returned';
        }

        if ($this->hasApprovedForLaunch()) {
            return 'approved_ready';
        }

        $latest = $this->latestApproval();

        if ($latest === null && $this->status === PaymentRequestStatus::Requested) {
            return 'no_rule';
        }

        return 'none';
    }

    public function isEditableBy(User $user): bool
    {
        if ($user->isAdm()) {
            return true;
        }

        if ($this->status === PaymentRequestStatus::Requested && $this->isAwaitingApproval()) {
            return false;
        }

        if ($user->isOperador()) {
            return in_array($this->status, [
                PaymentRequestStatus::Requested,
                PaymentRequestStatus::Launched,
            ], true);
        }

        if ($this->status !== PaymentRequestStatus::Requested) {
            return false;
        }

        if ($this->hasApprovedForLaunch()) {
            return false;
        }

        return $user->branches()->whereKey($this->branch_id)->exists();
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

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeAwaitingApprovalFor(Builder $query, User $user): Builder
    {
        return $query->whereHas('approvals', function (Builder $q) use ($user): void {
            $q->where('status', ApprovalStatus::Pending)
                ->where('approver_user_id', $user->getKey());
        });
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeAwaitingAnyApproval(Builder $query): Builder
    {
        return $query->whereHas(
            'approvals',
            fn (Builder $q): Builder => $q->where('status', ApprovalStatus::Pending),
        );
    }

    /**
     * @param  Builder<PaymentRequest>  $query
     * @return Builder<PaymentRequest>
     */
    public function scopeReturnedToRequester(Builder $query): Builder
    {
        return $query
            ->where('status', PaymentRequestStatus::Requested)
            ->whereDoesntHave(
                'approvals',
                fn (Builder $q): Builder => $q->where('status', ApprovalStatus::Pending),
            )
            ->whereHas('approvals', function (Builder $q): void {
                $q->where('status', ApprovalStatus::Rejected)
                    ->whereRaw('approvals.assigned_at = (
                        SELECT MAX(a2.assigned_at) FROM approvals a2
                        WHERE a2.payment_request_id = payment_requests.id
                    )');
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprintBankDetails(): array
    {
        $details = $this->bankDetails;

        if ($details === null) {
            return [];
        }

        $fields = [
            'digitable_line' => $details->digitable_line,
            'barcode' => $details->barcode,
            'pix_key_type' => $details->pix_key_type?->value ?? $details->pix_key_type,
            'pix_key' => $details->pix_key,
            'pix_qr_code' => $details->pix_qr_code,
            'holder_name' => $details->holder_name,
            'holder_document' => $details->holder_document,
            'bank_id' => $details->bank_id,
            'agency' => $details->agency,
            'agency_digit' => $details->agency_digit,
            'account_number' => $details->account_number,
            'account_digit' => $details->account_digit,
            'account_type' => $details->account_type?->value ?? $details->account_type,
            'deposit_type' => $details->deposit_type?->value ?? $details->deposit_type,
        ];

        $stable = [];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $stable[$key] = is_string($value) || is_numeric($value) ? (string) $value : $value;
        }

        ksort($stable);

        return $stable;
    }

    /**
     * @return list<array{id: string, path: string, size: int}>
     */
    private function fingerprintBoletoAttachments(): array
    {
        if ($this->payment_method !== PaymentMethod::Boleto) {
            return [];
        }

        return $this->attachments
            ->filter(fn (Attachment $attachment): bool => $attachment->type === AttachmentType::Boleto)
            ->sortBy('id')
            ->values()
            ->map(fn (Attachment $attachment): array => [
                'id' => (string) $attachment->getKey(),
                'path' => (string) $attachment->path,
                'size' => (int) $attachment->size,
            ])
            ->all();
    }
}
