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
        Schema::create('cnab_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_settlement_id')->index()
                ->constrained('payment_settlements')->restrictOnDelete();
            $table->foreignUuid('cnab_config_id')->index()
                ->constrained('cnab_configs')->restrictOnDelete();
            // Denormalized: snapshot of cnab_configs.layout used to resolve the adapter
            $table->string('layout', 30);
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('file_sequence')->nullable();
            $table->string('disk', 50)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('filename', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            // Denormalized: generated file metrics, immutable after generated
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            // Snapshot of config + account + branch used to generate this file
            $table->json('config_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignUuid('superseded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('supersede_reason', 500)->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->foreignUuid('downloaded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            // Literals must match CnabFileStatus::activeValues() (guarded by test)
            DB::statement(
                'CREATE UNIQUE INDEX cnab_files_settlement_active_unique ON cnab_files (payment_settlement_id) WHERE status IN (\'queued\', \'generating\', \'generated\') AND deleted_at IS NULL'
            );
            // No deleted_at on purpose: a file sequence is never reused, even for trashed files
            DB::statement(
                'CREATE UNIQUE INDEX cnab_files_config_sequence_unique ON cnab_files (cnab_config_id, file_sequence) WHERE file_sequence IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS cnab_files_settlement_active_unique');
            DB::statement('DROP INDEX IF EXISTS cnab_files_config_sequence_unique');
        }

        Schema::dropIfExists('cnab_files');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
