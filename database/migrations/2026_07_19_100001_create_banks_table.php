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
        Schema::create('banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 3); // COMPE — no ->unique()
            $table->string('name', 150);
            $table->string('ispb', 8)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX banks_code_unique ON banks (code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS banks_code_unique');
        }

        Schema::dropIfExists('banks');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
