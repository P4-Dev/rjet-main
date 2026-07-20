<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

final class BankSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, ispb: ?string}>
     */
    private const BANKS = [
        ['code' => '001', 'name' => 'Banco do Brasil', 'ispb' => '00000000'],
        ['code' => '033', 'name' => 'Santander', 'ispb' => '90400888'],
        ['code' => '104', 'name' => 'Caixa Econômica Federal', 'ispb' => '00360305'],
        ['code' => '237', 'name' => 'Bradesco', 'ispb' => '60746948'],
        ['code' => '341', 'name' => 'Itaú Unibanco', 'ispb' => '60701190'],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $bank) {
            Bank::query()->firstOrCreate(
                ['code' => $bank['code']],
                [
                    'name' => $bank['name'],
                    'ispb' => $bank['ispb'],
                    'is_active' => true,
                ],
            );
        }
    }
}
