<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class UserSeeder extends Seeder
{
    public function __construct(private readonly UserService $userService) {}

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@rjet.com'],
            [
                'name' => 'Administrador RJET',
                'role' => UserRole::Adm,
                'can_approve' => false,
                'is_active' => true,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        User::updateOrCreate(
            ['email' => 'operador@rjet.com'],
            [
                'name' => 'Operador RJET',
                'role' => UserRole::Operador,
                'can_approve' => true,
                'is_active' => true,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $cliente = User::updateOrCreate(
            ['email' => 'cliente@rjet.com'],
            [
                'name' => 'Cliente RJET',
                'role' => UserRole::Cliente,
                'can_approve' => false,
                'is_active' => true,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->linkClientToBranches($cliente);
    }

    /**
     * Vincula o cliente a filiais de empresas diferentes (multi-filial), com a
     * filial da Altitude marcada como padrão.
     */
    private function linkClientToBranches(User $cliente): void
    {
        $altitudeBranch = Branch::query()
            ->whereRelation('company', 'name', 'Altitude')
            ->orderBy('name')
            ->first();

        $glowBranch = Branch::query()
            ->whereRelation('company', 'name', 'Glow')
            ->orderBy('name')
            ->first();

        $branchIds = array_values(array_filter([
            $altitudeBranch?->getKey(),
            $glowBranch?->getKey(),
        ]));

        if ($branchIds === []) {
            return;
        }

        $this->userService->syncBranches($cliente, $branchIds, $altitudeBranch?->getKey());
    }
}
