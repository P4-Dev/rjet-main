<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_template_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_template_version_id')->index()
                ->constrained('import_template_versions')->cascadeOnDelete();
            $table->string('source_column', 120)->nullable();
            $table->string('target_field', 60);
            $table->string('default_value', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['import_template_version_id', 'target_field']);
            $table->unique(['import_template_version_id', 'source_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_template_mappings');
    }
};
