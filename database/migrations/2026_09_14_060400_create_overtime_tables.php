<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('reason');
            $table->text('work_report')->nullable();
            // 8-hour mark, or clock-in on a non-workday
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->boolean('is_late_claim')->default(false);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // When the work report was completed; null while the report is due
            $table->dateTime('submitted_at', 3)->nullable();
            $table->timestamps(3);

            $table->index(['status', 'user_id']);
        });

        Schema::create('overtime_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decided_by')->constrained('users');
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('note')->nullable();
            $table->dateTime('decided_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_decisions');
        Schema::dropIfExists('overtime_requests');
    }
};
