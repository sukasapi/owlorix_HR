<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->nullable()->unique();
            $table->enum('status', ['planned', 'active', 'done'])->default('planned');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at', 3);
            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('work_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->text('description');
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3);
            $table->string('evidence_url', 500);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'started_at']);
            $table->index(['project_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_activity_logs');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};
