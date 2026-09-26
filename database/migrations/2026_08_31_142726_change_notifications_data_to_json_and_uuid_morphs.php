<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->changePostgresColumns();

            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->json('data')->change();
            $table->uuid('notifiable_id')->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');

            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->text('data')->change();
        });
    }

    private function changePostgresColumns(): void
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');

        if (Schema::getColumnType('notifications', 'notifiable_id') === 'uuid') {
            return;
        }

        // bigint IDs cannot reference UUID users; leftover rows cannot be converted.
        DB::table('notifications')->delete();

        DB::statement('ALTER TABLE notifications ALTER COLUMN notifiable_id TYPE uuid USING notifiable_id::text::uuid');
    }
};
