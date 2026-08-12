<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasBlameable, HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'legal_name',
        'document',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return HasMany<BranchBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BranchBankAccount::class);
    }

    /**
     * @return HasMany<CostCenter, $this>
     */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    /**
     * @return HasMany<PaymentRequest, $this>
     */
    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    /**
     * @return HasMany<ApprovalRule, $this>
     */
    public function approvalRules(): HasMany
    {
        return $this->hasMany(ApprovalRule::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filtra as filiais visíveis para o usuário informado.
     *
     * Adm/Operador enxergam tudo; Cliente apenas filiais vinculadas.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->seesAllBranches()) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q) => $q->whereKey($user->getKey()));
    }
}
