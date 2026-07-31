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
        Schema::create('payment_request_bank_details', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // NOT ->unique(): partial unique index below keeps 1:1 while allowing soft-deleted rows.
            $table->foreignUuid('payment_request_id')->index()->constrained('payment_requests')->cascadeOnDelete();
            $table->string('deposit_type', 20)->nullable()->index();
            $table->string('pix_key_type', 20)->nullable();
            $table->string('pix_key', 100)->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->string('digitable_line', 54)->nullable();
            $table->string('barcode', 48)->nullable();
            $table->foreignUuid('bank_id')->nullable()->index()->constrained('banks')->nullOnDelete();
            $table->string('agency', 10)->nullable();
            $table->string('agency_digit', 2)->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('account_digit', 2)->nullable();
            $table->string('account_type', 20)->nullable();
            $table->string('holder_name', 150)->nullable();
            // Digits only (CPF 11 / CNPJ 14) — same convention as suppliers.document.
            $table->string('holder_document', 20)->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX payment_request_bank_details_request_unique ON payment_request_bank_details (payment_request_id) WHERE deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX IF EXISTS payment_request_bank_details_request_unique');
        }

        Schema::dropIfExists('payment_request_bank_details');
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
