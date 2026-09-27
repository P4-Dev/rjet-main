<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentBatchItemStatus;
use App\Enums\AttachmentBatchStatus;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\AttachmentBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

final class AttachmentBatch extends Model
{
    /** @use HasFactory<AttachmentBatchFactory> */
    use HasAttachments, HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'items_count',
        'classified_count',
        'renamed_count',
        'failed_count',
        'failure_reason',
        'classified_at',
        'renaming_started_at',
        'renamed_at',
        'naming_generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttachmentBatchStatus::class,
            'items_count' => 'integer',
            'classified_count' => 'integer',
            'renamed_count' => 'integer',
            'failed_count' => 'integer',
            'classified_at' => 'datetime',
            'renaming_started_at' => 'datetime',
            'renamed_at' => 'datetime',
            'naming_generated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AttachmentBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AttachmentBatchItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasManyThrough<AttachmentBatchItemClassification, AttachmentBatchItem, $this>
     */
    public function classifications(): HasManyThrough
    {
        return $this->hasManyThrough(AttachmentBatchItemClassification::class, AttachmentBatchItem::class);
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

    public function isPendingClassification(): bool
    {
        return $this->status === AttachmentBatchStatus::PendingClassification;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function allItemsClassified(): bool
    {
        return $this->items()->exists()
            && ! $this->items()->where('status', AttachmentBatchItemStatus::Pending)->exists();
    }

    /**
     * Items still `classified` (e.g. job died mid-batch) or `failed` are eligible for a rename retry.
     */
    public function hasItemsPendingRename(): bool
    {
        return $this->items()
            ->whereIn('status', [AttachmentBatchItemStatus::Classified, AttachmentBatchItemStatus::Failed])
            ->exists();
    }

    public function isRenameRetryable(): bool
    {
        return in_array($this->status, [AttachmentBatchStatus::PartiallyFailed, AttachmentBatchStatus::Failed], true)
            && $this->hasItemsPendingRename();
    }
}
