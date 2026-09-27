<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_batch_item_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Explicit names: generated ones exceed Postgres' 63-char identifier limit and collide when truncated.
            $table->foreignUuid('attachment_batch_item_id')->index('abi_classifications_item_id_index')
                ->constrained('attachment_batch_items', 'id', 'abi_classifications_item_id_foreign')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->index()
                ->constrained('users')->nullOnDelete();
            $table->string('destination_type', 30);
            $table->foreignUuid('payment_request_id')->nullable()->index()
                ->constrained('payment_requests', 'id', 'abi_classifications_payment_request_id_foreign')->nullOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->index()
                ->constrained('suppliers')->nullOnDelete();
            $table->string('operational_label', 120)->nullable();
            // Append-only event time: no updated_at, no soft deletes.
            $table->timestampTz('classified_at');

            $table->index(['attachment_batch_item_id', 'classified_at'], 'abi_classifications_item_classified_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_batch_item_classifications');
    }
};
