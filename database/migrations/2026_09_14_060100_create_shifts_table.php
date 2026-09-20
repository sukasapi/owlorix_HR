<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // Asia/Jakarta date of clock-in
            $table->date('work_date');
            $table->boolean('is_workday');
            $table->dateTime('clock_in_at', 3);
            $table->unsignedInteger('regular_before_minutes')->default(0);
            $table->dateTime('regular_ends_at', 3)->nullable();
            $table->dateTime('clock_out_at', 3)->nullable();
            $table->dateTime('last_seen_at', 3);
            $table->enum('status', ['open', 'prompted', 'overtime', 'report_due', 'closed', 'interrupted', 'needs_review']);
            $table->enum('end_reason', ['manual', 'auto_no_answer', 'shutdown_timeout', 'superadmin'])->nullable();
            $table->enum('overtime_end_reason', ['clock_out', 'presence_check_no_answer'])->nullable();
            $table->boolean('is_short')->default(false);
            $table->unsignedInteger('regular_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('idle_minutes')->default(0);
            $table->unsignedInteger('interruption_minutes')->default(0);
            $table->json('flags');
            $table->unsignedSmallInteger('regular_limit_minutes');
            $table->text('note')->nullable();
            $table->timestamps(3);

            // One shift without a clock-out per person. A stored generated column keeps the rule portable to MariaDB,
            // where a unique index on NULLs allows any number of closed rows.
            $table->unsignedBigInteger('open_user_id')->nullable()->storedAs('if(`clock_out_at` is null, `user_id`, null)');
            $table->unique('open_user_id');

            $table->index(['user_id', 'work_date']);
            $table->index(['user_id', 'clock_in_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
