<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_reassignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_id')->index()->constrained('approvals')->cascadeOnDelete();
            $table->foreignUuid('from_approver_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('to_approver_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reassignments');
    }
};
