<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('import_batch_id')->index()
                ->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('target_field', 60)->nullable();
            $table->string('message', 500);
            $table->json('raw_values')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_errors');
    }
};
