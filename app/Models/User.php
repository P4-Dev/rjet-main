<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasBlameable;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasBlameable, HasFactory, HasUuid, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'can_approve',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'can_approve' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function defaultBranch(): BelongsToMany
    {
        return $this->branches()->wherePivot('is_default', true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeApprovers(Builder $query): Builder
    {
        return $query->where('can_approve', true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeByRole(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role);
    }

    public function isAdm(): bool
    {
        return $this->role === UserRole::Adm;
    }

    public function isOperador(): bool
    {
        return $this->role === UserRole::Operador;
    }

    public function isCliente(): bool
    {
        return $this->role === UserRole::Cliente;
    }

    public function canApprove(): bool
    {
        return (bool) $this->can_approve;
    }

    /**
     * Indica se este é o último administrador ativo do sistema.
     */
    public function isLastActiveAdmin(): bool
    {
        if ($this->role !== UserRole::Adm || ! $this->is_active) {
            return false;
        }

        return ! self::query()
            ->where('role', UserRole::Adm)
            ->where('is_active', true)
            ->whereKeyNot($this->getKey())
            ->exists();
    }
}
