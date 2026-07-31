<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->index();
            $table->foreignUuid('changed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['payment_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_status_history');
    }
};
