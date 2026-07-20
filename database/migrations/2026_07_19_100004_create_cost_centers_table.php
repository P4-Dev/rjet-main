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
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
            $table->string('code', 30); // no ->unique()
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX cost_centers_branch_code_unique ON cost_centers (branch_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS cost_centers_branch_code_unique');
        }

        Schema::dropIfExists('cost_centers');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
