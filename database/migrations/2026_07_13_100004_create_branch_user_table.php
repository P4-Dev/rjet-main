<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // branch_id coberto pelo unique(branch_id, user_id) via leftmost-prefix.
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['branch_id', 'user_id']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX branch_user_user_default_unique
                ON branch_user (user_id) WHERE is_default = true
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS branch_user_user_default_unique');
        }

        Schema::dropIfExists('branch_user');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
