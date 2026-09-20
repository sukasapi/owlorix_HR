<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            // Machine GUID based id sent by the desktop app
            $table->string('id', 64)->primary();
            $table->string('hostname', 100);
            $table->string('app_version', 20);
            $table->dateTime('last_seen_at', 3);
            $table->dateTime('revoked_at', 3)->nullable();
            $table->timestamps(3);
        });

        Schema::create('devices_users', function (Blueprint $table) {
            $table->string('device_id', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 14-day offline sign-in rule (docs/02-attendance-rules.md 3.8.5)
            $table->dateTime('last_online_sign_in_at', 3);

            $table->primary(['device_id', 'user_id']);
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices_users');
        Schema::dropIfExists('devices');
    }
};
