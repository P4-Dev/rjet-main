<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

final class DevelopmentSeeder extends Seeder
{
    /**
     * Volume adicional para desenvolvimento local. Só executa se ainda não há
     * dados de volume além das empresas base (Altitude/Glow).
     */
    public function run(): void
    {
        if (Company::query()->count() > count(['Altitude', 'Glow'])) {
            return;
        }

        Company::factory()
            ->count(3)
            ->create()
            ->each(function (Company $company): void {
                Branch::factory()
                    ->count(2)
                    ->for($company)
                    ->withBankAccounts(2)
                    ->create();
            });
    }
}
