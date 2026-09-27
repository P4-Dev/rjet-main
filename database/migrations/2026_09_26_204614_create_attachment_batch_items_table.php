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
        Schema::create('attachment_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Cascade applies to hard/force delete only; soft cascade lives in AttachmentBatchObserver.
            $table->foreignUuid('attachment_batch_id')->index()
                ->constrained('attachment_batches')->cascadeOnDelete();
            $table->foreignUuid('attachment_id')->index()
                ->constrained('attachments')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->string('destination_type', 30)->nullable()->index();
            $table->foreignUuid('payment_request_id')->nullable()->index()
                ->constrained('payment_requests')->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->index()
                ->constrained('suppliers')->nullOnDelete();
            $table->string('operational_label', 120)->nullable();
            $table->foreignUuid('classified_by')->nullable()->index()
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('classified_at')->nullable();
            $table->string('rename_error', 500)->nullable();
            $table->timestampTz('renamed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['attachment_batch_id', 'sort_order']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX attachment_batch_items_attachment_id_unique ON attachment_batch_items (attachment_id) WHERE deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS attachment_batch_items_attachment_id_unique');
        }

        Schema::dropIfExists('attachment_batch_items');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
