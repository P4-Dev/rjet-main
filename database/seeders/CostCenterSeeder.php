<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CostCenter;
use Illuminate\Database\Seeder;

final class CostCenterSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->each(function (Branch $branch): void {
            CostCenter::query()->firstOrCreate(
                [
                    'branch_id' => $branch->getKey(),
                    'code' => 'ADM',
                ],
                [
                    'name' => 'Administrativo',
                    'description' => 'Centro de custo administrativo',
                    'sort_order' => 1,
                    'is_active' => true,
                ],
            );

            CostCenter::query()->firstOrCreate(
                [
                    'branch_id' => $branch->getKey(),
                    'code' => 'OPE',
                ],
                [
                    'name' => 'Operacional',
                    'description' => 'Centro de custo operacional',
                    'sort_order' => 2,
                    'is_active' => true,
                ],
            );
        });
    }
}
