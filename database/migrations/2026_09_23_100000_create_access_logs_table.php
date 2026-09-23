<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monitor aktivitas (docs/14 2.1). Append-only; rows older than monitoring.access_log_days are pruned daily.
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->string('method', 8)->nullable();
            $table->string('route_name', 120)->nullable();
            // Never the query string: it can hold search terms
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            // Only for failed sign-ins
            $table->string('username', 100)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 3);

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
