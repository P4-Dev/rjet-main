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
        Schema::create('cnab_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_bank_account_id')->index()
                ->constrained('branch_bank_accounts')->restrictOnDelete();
            $table->string('layout', 30);
            $table->string('company_name', 30)->nullable();
            $table->string('agreement_code', 20)->nullable();
            $table->string('wallet_code', 10)->nullable();
            $table->string('payment_type_code', 2)->default('20');
            $table->unsignedInteger('last_file_sequence')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX cnab_configs_account_unique ON cnab_configs (branch_bank_account_id) WHERE deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS cnab_configs_account_unique');
        }

        Schema::dropIfExists('cnab_configs');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
