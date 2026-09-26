<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_template_version_id',
        'status',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'mappings_snapshot',
        'total_rows',
        'success_count',
        'error_count',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'mappings_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'size' => 'integer',
            'total_rows' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ImportTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ImportTemplateVersion::class, 'import_template_version_id');
    }

    /**
     * @return HasMany<ImportBatchError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportBatchError::class);
    }

    /**
     * @return HasMany<PaymentRequest, $this>
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isPending(): bool
    {
        return $this->status === ImportBatchStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === ImportBatchStatus::Processing;
    }
}
