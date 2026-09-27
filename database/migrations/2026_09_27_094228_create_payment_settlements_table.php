<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Denormalized: transitive via branch_bank_account_id, kept for listing/scope; consistency enforced in service
            $table->foreignUuid('branch_id')->index()
                ->constrained('branches')->restrictOnDelete();
            $table->foreignUuid('branch_bank_account_id')->index()
                ->constrained('branch_bank_accounts')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->date('settlement_date');
            // Denormalized: list cache — source of truth is active items (recalculated in service)
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->foreignUuid('settled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['branch_id', 'settlement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settlements');
    }
};
