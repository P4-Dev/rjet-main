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
        Schema::create('approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignUuid('approval_rule_id')->nullable()->index()->constrained('approval_rules')->nullOnDelete();
            $table->foreignUuid('approver_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->text('reason')->nullable();
            $table->decimal('amount_snapshot', 10, 2);
            $table->uuid('branch_id_snapshot');
            $table->uuid('supplier_id_snapshot');
            $table->string('material_fingerprint', 64);
            $table->timestampTz('assigned_at');
            $table->timestampTz('due_at');
            $table->timestampTz('decided_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('escalated_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['payment_request_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX approvals_payment_request_pending_unique ON approvals (payment_request_id) WHERE status = \'pending\''
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS approvals_payment_request_pending_unique');
        }

        Schema::dropIfExists('approvals');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
