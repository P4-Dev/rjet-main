<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CostCenter extends Model
{
    /** @use HasFactory<CostCenterFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<CostCenter>  $query
     * @return Builder<CostCenter>
     */
    public function scopeForBranch(Builder $query, Branch|string $branch): Builder
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $query->where('branch_id', $branchId);
    }
}
