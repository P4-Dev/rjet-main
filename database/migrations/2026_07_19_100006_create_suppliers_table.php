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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('person_type', 20)->index();
            $table->string('document', 20); // digits only — no ->unique()
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 20)->nullable();
            $table->string('default_payment_method', 20)->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement('CREATE UNIQUE INDEX suppliers_document_unique ON suppliers (document) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS suppliers_document_unique');
        }

        Schema::dropIfExists('suppliers');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
