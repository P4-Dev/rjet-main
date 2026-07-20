<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('addressable');
            $table->string('type', 20)->default('main');
            $table->string('street');
            $table->string('number', 20)->nullable();
            $table->string('complement', 100)->nullable();
            $table->string('neighborhood');
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip_code', 10);
            $table->string('country', 2)->default('BR');
            $table->boolean('is_default')->default(false)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['addressable_type', 'addressable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
