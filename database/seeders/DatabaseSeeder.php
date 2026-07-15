<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * A ordem importa: empresas/filiais antes dos usuários (o cliente é
     * vinculado a filiais existentes).
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            UserSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call([
                DevelopmentSeeder::class,
            ]);
        }
    }
}
