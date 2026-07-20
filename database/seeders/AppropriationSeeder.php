<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Appropriation;
use App\Models\Company;
use Illuminate\Database\Seeder;

final class AppropriationSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(function (Company $company): void {
            Appropriation::query()->firstOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'code' => 'DESP',
                ],
                [
                    'name' => 'Despesas gerais',
                    'description' => 'Apropriação padrão de despesas',
                    'sort_order' => 1,
                    'is_active' => true,
                ],
            );

            Appropriation::query()->firstOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'code' => 'SERV',
                ],
                [
                    'name' => 'Serviços',
                    'description' => 'Apropriação de serviços',
                    'sort_order' => 2,
                    'is_active' => true,
                ],
            );
        });
    }
}
