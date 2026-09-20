<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Heartbeats are not stored; they update shifts.last_seen_at and devices.last_seen_at.
        Schema::create('attendance_events', function (Blueprint $table) {
            // UUID v7 created on the PC, so resends are no-ops
            $table->char('id', 36)->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('device_id', 64);
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->dateTime('occurred_at', 3);
            $table->dateTime('occurred_at_device', 3);
            $table->string('boot_id', 40);
            $table->unsignedBigInteger('uptime_ms');
            $table->integer('server_offset_ms');
            $table->boolean('offline');
            $table->json('payload');
            $table->dateTime('received_at', 3);

            $table->foreign('device_id')->references('id')->on('devices');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['device_id', 'boot_id', 'uptime_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
