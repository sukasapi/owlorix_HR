<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PC diam reviews (2026-09-25): a lead marks a quiet period as checked, or asks the person why, and the person
 * answers on Hari ini. idle_periods rows are rebuilt from events, so a review points at the period by its shift and
 * start time, which stay the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idle_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at', 3);
            $table->foreignId('user_id')->constrained();
            // checked | asked | answered
            $table->string('status', 16);
            $table->string('question', 500)->nullable();
            $table->text('answer')->nullable();
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('asked_at', 3)->nullable();
            $table->dateTime('answered_at', 3)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at', 3)->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'started_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idle_reviews');
    }
};
