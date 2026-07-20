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
        Schema::create('supplier_company_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_id')->index()->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('company_id')->index()->constrained('companies')->cascadeOnDelete();
            $table->string('payment_method', 20)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX supplier_company_payment_methods_pair_unique
                ON supplier_company_payment_methods (supplier_id, company_id) WHERE deleted_at IS NULL
                SQL);
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS supplier_company_payment_methods_pair_unique');
        }

        Schema::dropIfExists('supplier_company_payment_methods');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
