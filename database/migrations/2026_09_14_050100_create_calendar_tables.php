<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_week', function (Blueprint $table) {
            // ISO weekday: 1 = Monday ... 7 = Sunday
            $table->unsignedTinyInteger('weekday')->primary();
            $table->boolean('is_workday');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('work_week')->insert(collect(range(1, 7))->map(fn (int $day) => [
            'weekday' => $day,
            'is_workday' => $day <= 5,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        Schema::create('calendar_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->enum('type', ['holiday', 'studio_day_off', 'workday']);
            $table->string('name', 120);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('opened_workdays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('scope_type', ['team', 'user']);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('opened_by')->constrained('users');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Closing a day soft-deletes the row; reopening restores it, so the unique key holds.
            $table->unique(['date', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opened_workdays');
        Schema::dropIfExists('calendar_days');
        Schema::dropIfExists('work_week');
    }
};
