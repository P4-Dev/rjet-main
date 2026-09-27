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
        Schema::create('payment_settlement_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // applies to hard/force delete only
            $table->foreignUuid('payment_settlement_id')->index()
                ->constrained('payment_settlements')->cascadeOnDelete();
            $table->foreignUuid('payment_request_id')->index()
                ->constrained('payment_requests')->restrictOnDelete();
            // Snapshot of payment_requests.net_amount at selection time
            $table->decimal('amount', 10, 2);
            $table->timestampTz('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX payment_settlement_items_active_request_unique ON payment_settlement_items (payment_request_id) WHERE released_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS payment_settlement_items_active_request_unique');
        }

        Schema::dropIfExists('payment_settlement_items');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
