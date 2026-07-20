<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\AppropriationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Appropriation extends Model
{
    /** @use HasFactory<AppropriationFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  Builder<Appropriation>  $query
     * @return Builder<Appropriation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Appropriation>  $query
     * @return Builder<Appropriation>
     */
    public function scopeForCompany(Builder $query, Company|string $company): Builder
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $query->where('company_id', $companyId);
    }
}
