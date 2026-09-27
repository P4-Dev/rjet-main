<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CnabFileStatus;
use App\Enums\CnabLayout;
use App\Enums\PaymentSettlementStatus;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\CnabFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CnabFile extends Model
{
    /** @use HasFactory<CnabFileFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_settlement_id',
        'cnab_config_id',
        'layout',
        'status',
        'file_sequence',
        'disk',
        'path',
        'filename',
        'size',
        'checksum',
        'records_count',
        'items_count',
        'total_amount',
        'config_snapshot',
        'failure_reason',
        'started_at',
        'generated_at',
        'superseded_at',
        'superseded_by',
        'supersede_reason',
        'downloaded_at',
        'downloaded_by',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CnabFileStatus::class,
            'layout' => CnabLayout::class,
            'config_snapshot' => 'array',
            'total_amount' => 'decimal:2',
            'file_sequence' => 'integer',
            'size' => 'integer',
            'records_count' => 'integer',
            'items_count' => 'integer',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
            'superseded_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PaymentSettlement::class, 'payment_settlement_id')->withTrashed();
    }

    /**
     * @return BelongsTo<CnabConfig, $this>
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(CnabConfig::class, 'cnab_config_id')->withTrashed();
    }

    /**
     * @return HasMany<CnabFileItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CnabFileItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function downloader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function superseder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === CnabFileStatus::Generated && filled($this->path);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isRetryable(): bool
    {
        return $this->status === CnabFileStatus::Failed
            && $this->settlement?->status === PaymentSettlementStatus::Draft;
    }
}
