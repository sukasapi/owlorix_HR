<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->enum('status', ['planned', 'active', 'done'])->default('active');
            // Decides on proposed tasks and reviews evidence (docs/13)
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_project_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->enum('status', ['proposed', 'rejected', 'todo', 'in_progress', 'in_review', 'changes_requested', 'done'])->default('todo');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->boolean('evidence_required')->default(true);
            // Decision on a proposal: approved (todo) or rejected, with the note shown to the proposer
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at', 3)->nullable();
            $table->text('decision_note')->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sub_project_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('task_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            // No cascade: MySQL refuses cascading keys on a base column of the stored open_user_id below
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            // Set when the session was turned into a work log row on submit
            $table->foreignId('work_activity_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // One running timer per person
            $table->unsignedBigInteger('open_user_id')->nullable()->storedAs('if(`ended_at` is null, `user_id`, null)');
            $table->unique('open_user_id');
            $table->index(['task_id', 'user_id']);
        });

        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->text('note');
            $table->string('evidence_url', 500)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name', 190)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->enum('review_status', ['pending', 'approved', 'changes_requested'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at', 3)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        // A work log made from task sessions points back to its task
        Schema::table('work_activity_logs', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });
        Schema::dropIfExists('task_submissions');
        Schema::dropIfExists('task_work_sessions');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('sub_projects');
    }
};
