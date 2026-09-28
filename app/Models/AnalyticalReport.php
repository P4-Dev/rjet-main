<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticalReportStatus;
use App\Enums\ReportDateBasis;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use App\Observers\AnalyticalReportObserver;
use Database\Factories\AnalyticalReportFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(AnalyticalReportObserver::class)]
final class AnalyticalReport extends Model
{
    /** @use HasFactory<AnalyticalReportFactory> */
    use HasBlameable, HasFactory, HasUuid, Prunable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'company_id',
        'branch_id',
        'date_basis',
        'period_start',
        'period_end',
        'statuses',
        'disk',
        'path',
        'filename',
        'size',
        'rows_count',
        'attachments_count',
        'failure_reason',
        'started_at',
        'generated_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnalyticalReportStatus::class,
            'date_basis' => ReportDateBasis::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'statuses' => 'array',
            'size' => 'integer',
            'rows_count' => 'integer',
            'attachments_count' => 'integer',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function isDownloadable(): bool
    {
        return $this->status === AnalyticalReportStatus::Generated && filled($this->path);
    }

    public function isStale(): bool
    {
        return $this->status->isInProgress()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subMinutes((int) config('rjet.reports.stale_after_minutes')));
    }

    public function isRetryable(): bool
    {
        return $this->status === AnalyticalReportStatus::Failed || $this->isStale();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::withTrashed()
            ->where('created_at', '<=', now()->subDays((int) config('rjet.reports.retention_days')));
    }

    /**
     * @param  Builder<AnalyticalReport>  $query
     * @return Builder<AnalyticalReport>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), AnalyticalReportStatus::inProgressValues());
    }

    /**
     * @param  Builder<AnalyticalReport>  $query
     * @return Builder<AnalyticalReport>
     */
    public function scopeCreatedBy(Builder $query, User $user): Builder
    {
        return $query->where($query->qualifyColumn('created_by'), $user->getKey());
    }
}
