<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->index()->constrained('branches')->cascadeOnDelete();
            // STUB Fase 2: referência a `banks.id`. Sem constrained() pois a tabela `banks`
            // ainda não existe. Constraint + backfill (via bank_code) entram na Fase 2.
            $table->uuid('bank_id')->nullable();
            $table->string('bank_code', 3)->index();
            $table->string('bank_name');
            $table->string('agency', 10);
            $table->string('agency_digit', 2)->nullable();
            $table->string('account_number', 20);
            $table->string('account_digit', 2)->nullable();
            $table->string('account_type', 20)->nullable(); // cast AccountType
            $table->string('holder_name')->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX branch_bank_accounts_branch_default_unique
                ON branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS branch_bank_accounts_branch_default_unique');
        }

        Schema::dropIfExists('branch_bank_accounts');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
