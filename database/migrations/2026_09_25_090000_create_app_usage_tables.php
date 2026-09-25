<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aktivitas detail (owner decision 2026-09-25): which application and window title was in front on a studio PC while
 * the person was clocked in (covered by the Peraturan Perusahaan). Seen by Superadmin only. Rows older than `monitoring.app_usage_active_days` move to
 * the archive table every night; nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['app_usage_sessions', 'app_usage_archive'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                // Id made by the desktop app, so a batch sent twice is stored once
                $table->uuid('client_id')->unique();
                $table->foreignId('user_id')->constrained();
                $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
                $table->string('device_id', 64);
                $table->string('app_name', 120);
                $table->string('exe', 160);
                $table->string('window_title', 255)->nullable();
                $table->boolean('is_browser')->default(false);
                $table->dateTime('started_at', 3);
                $table->dateTime('ended_at', 3);
                $table->unsignedInteger('seconds');
                $table->dateTime('created_at', 3);
                if ($name === 'app_usage_archive') {
                    $table->dateTime('archived_at', 3);
                }
                $table->index(['user_id', 'started_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_usage_archive');
        Schema::dropIfExists('app_usage_sessions');
    }
};
