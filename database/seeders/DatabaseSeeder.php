<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

final class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: companies/branches before users; banks before backfill.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            UserSeeder::class,
            BankSeeder::class,
            CostCenterSeeder::class,
            AppropriationSeeder::class,
            SupplierSeeder::class,
        ]);

        Artisan::call('banks:backfill-branch-accounts');

        if (app()->environment('local', 'testing')) {
            $this->call([
                DevelopmentSeeder::class,
                PaymentRequestSeeder::class,
                ApprovalRuleSeeder::class,
            ]);
        }
    }
}