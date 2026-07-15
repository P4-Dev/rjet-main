<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\Company;
use Illuminate\Database\Seeder;

final class CompanySeeder extends Seeder
{
    /**
     * Empresas base do ambiente (Altitude/Glow), cada uma com 2 filiais e uma
     * conta bancária padrão. Idempotente via firstOrCreate (chaveado por nome e
     * por company_id + nome de filial).
     *
     * @var array<int, array{name: string, branches: array<int, string>}>
     */
    private const COMPANIES = [
        ['name' => 'Altitude', 'branches' => ['Altitude Matriz', 'Altitude Filial Sul']],
        ['name' => 'Glow', 'branches' => ['Glow Matriz', 'Glow Filial Norte']],
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as $data) {
            $company = Company::firstOrCreate(
                ['name' => $data['name']],
                [
                    'legal_name' => $data['name'].' S.A.',
                    'document' => fake()->unique()->cnpj(false),
                    'is_active' => true,
                ],
            );

            foreach ($data['branches'] as $branchName) {
                $branch = Branch::firstOrCreate(
                    ['company_id' => $company->getKey(), 'name' => $branchName],
                    [
                        'legal_name' => $branchName.' LTDA',
                        'document' => fake()->unique()->cnpj(false),
                        'is_active' => true,
                    ],
                );

                if ($branch->bankAccounts()->doesntExist()) {
                    BranchBankAccount::factory()->default()->for($branch)->create();
                }
            }
        }
    }
}
