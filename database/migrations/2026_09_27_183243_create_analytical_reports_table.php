<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytical_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('queued')->index();
            // Filter snapshot; null = all
            $table->foreignUuid('company_id')->nullable()->index()->constrained('companies')->restrictOnDelete();
            // Filter snapshot; null = all
            $table->foreignUuid('branch_id')->nullable()->index()->constrained('branches')->restrictOnDelete();
            $table->string('date_basis', 20)->default('due_date');
            $table->date('period_start');
            $table->date('period_end');
            // Denormalized: filter snapshot, never queried by SQL; null = all statuses
            $table->json('statuses')->nullable();
            // Snapshot of rjet.reports.disk at request time
            $table->string('disk', 50);
            $table->string('path', 500)->nullable();
            $table->string('filename', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            // Denormalized: generated file metrics, immutable after generated
            $table->unsignedInteger('rows_count')->default(0);
            // Denormalized: generated file metrics, immutable after generated
            $table->unsignedInteger('attachments_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytical_reports');
    }
};
