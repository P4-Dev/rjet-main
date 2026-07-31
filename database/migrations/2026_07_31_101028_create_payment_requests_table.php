<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->index()->constrained('suppliers')->restrictOnDelete();
            $table->foreignUuid('cost_center_id')->index()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignUuid('appropriation_id')->nullable()->index()->constrained('appropriations')->nullOnDelete();
            $table->string('payment_method', 20)->index();
            $table->string('status', 20)->default('requested')->index();
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            // Denormalized: net amount is persisted for listings/CNAB; recalculated by PaymentRequestService.
            $table->decimal('net_amount', 10, 2);
            $table->date('due_date')->index();
            $table->text('notes')->nullable();
            // Denormalized: attachment existence cache, synced by AttachmentObserver.
            $table->boolean('has_attachments')->default(false)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
