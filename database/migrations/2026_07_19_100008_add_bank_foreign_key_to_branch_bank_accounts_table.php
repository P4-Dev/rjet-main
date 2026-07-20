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
        // bank_code / bank_name remain as operational snapshots synced from banks
        // via BankService / BranchBankAccountObserver when bank_id is set or Bank updates.
        Schema::table('branch_bank_accounts', function (Blueprint $table) {
            $table->index('bank_id');
            $table->foreign('bank_id')->references('id')->on('banks')->nullOnDelete();
        });

        // SQLite rebuilds the table when adding FKs and drops partial WHERE clauses —
        // re-apply the Phase 1 partial unique index after the alter.
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS branch_bank_accounts_branch_default_unique');
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX branch_bank_accounts_branch_default_unique
                ON branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL
                SQL);
        }
    }

    public function down(): void
    {
        Schema::table('branch_bank_accounts', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->dropIndex(['bank_id']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS branch_bank_accounts_branch_default_unique');
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX branch_bank_accounts_branch_default_unique
                ON branch_bank_accounts (branch_id) WHERE is_default = true AND deleted_at IS NULL
                SQL);
        }
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
