<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('contactable');
            $table->string('type', 20)->default('main');
            $table->string('name');
            $table->string('email')->nullable()->index();
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['contactable_type', 'contactable_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
