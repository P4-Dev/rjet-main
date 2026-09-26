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
        Schema::create('import_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_template_id')->index()->constrained('import_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(false);
            $table->timestampTz('published_at');
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->softDeletesTz();

            $table->unique(['import_template_id', 'version']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX import_template_versions_current_unique
                 ON import_template_versions (import_template_id)
                 WHERE is_current = true AND deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS import_template_versions_current_unique');
        }

        Schema::dropIfExists('import_template_versions');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
