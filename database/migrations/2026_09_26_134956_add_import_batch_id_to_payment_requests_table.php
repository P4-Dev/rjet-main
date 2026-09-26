<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->foreignUuid('import_batch_id')->nullable()->index()
                ->constrained('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('import_batch_id');
        });
    }
};
