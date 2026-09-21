<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time desktop → web sign-in links (docs/11-web-handoff.md).
        Schema::create('web_handoffs', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->dateTime('expires_at', 3);
            $table->dateTime('used_at', 3)->nullable();
            $table->timestamps(3);

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->index(['user_id', 'device_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_handoffs');
    }
};
