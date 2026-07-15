<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // cascadeOnDelete garante integridade apenas em hard delete/forceDelete;
            // cascade lógico de soft delete é feito pelo CompanyObserver.
            $table->foreignUuid('company_id')->index()->constrained('companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('legal_name', 200); // obrigatório (diverge de Company)
            $table->string('document', 20); // CNPJ da filial — sem ->unique()
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX branches_document_unique ON branches (document) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS branches_document_unique');
        }

        Schema::dropIfExists('branches');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
