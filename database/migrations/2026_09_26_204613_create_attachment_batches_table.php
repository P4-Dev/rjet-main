<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 30)->default('pending_classification')->index();
            // Denormalized: UI cache — source of truth is items()
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('classified_count')->default(0);
            $table->unsignedInteger('renamed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('classified_at')->nullable();
            $table->timestampTz('renaming_started_at')->nullable();
            $table->timestampTz('renamed_at')->nullable();
            $table->timestampTz('naming_generated_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_batches');
    }
};
