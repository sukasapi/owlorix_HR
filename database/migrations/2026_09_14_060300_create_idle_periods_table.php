<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idle_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->enum('tag', ['rendering', 'meeting', 'break', 'other'])->nullable();
            $table->string('note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idle_periods');
    }
};
