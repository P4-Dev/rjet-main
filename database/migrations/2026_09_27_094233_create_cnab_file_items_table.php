<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnab_file_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // applies to hard/force delete only
            $table->foreignUuid('cnab_file_id')->index()
                ->constrained('cnab_files')->cascadeOnDelete();
            $table->foreignUuid('payment_settlement_item_id')->index()
                ->constrained('payment_settlement_items')->restrictOnDelete();
            $table->string('payment_type', 20);
            $table->string('payment_form_code', 2)->nullable();
            $table->unsignedSmallInteger('batch_number')->nullable();
            $table->unsignedInteger('record_sequence')->nullable();
            // Snapshot of what was written to the remittance file
            $table->string('reference', 20);
            $table->decimal('amount', 10, 2);
            $table->boolean('is_valid')->default(true);
            $table->json('validation_errors')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['cnab_file_id', 'payment_settlement_item_id']);
            $table->unique(['cnab_file_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnab_file_items');
    }
};
