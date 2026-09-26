<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportFileFormat;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\ImportTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ImportTemplate extends Model
{
    /** @use HasFactory<ImportTemplateFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company_id',
        'branch_id',
        'accepted_format',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_format' => ImportFileFormat::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ImportTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ImportTemplateVersion::class);
    }

    /**
     * @return HasOne<ImportTemplateVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ImportTemplateVersion::class)
            ->where('is_current', true)
            ->whereNull('deleted_at');
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
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdm()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}
