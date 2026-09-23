<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** docs/14 section 3: production pipeline stages on tasks, project milestones, hour budgets. */
return new class extends Migration
{
    /** Proposed default stages (docs/14 3.1); the owner edits them at /admin/pipeline. */
    private const DEFAULT_STAGES = [
        'pre_production' => ['Naskah', 'Desain karakter', 'Storyboard', 'Animatic'],
        'production' => ['Modeling', 'Texturing', 'Rigging', 'Layout', 'Animasi', 'FX', 'Lighting', 'Render'],
        'post_production' => ['Compositing', 'Editing', 'Sound', 'Color grading'],
    ];

    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->enum('phase', ['pre_production', 'production', 'post_production']);
            $table->string('name', 60)->unique();
            // Order inside the phase
            $table->unsignedSmallInteger('sort')->default(0);
            // An inactive stage cannot be picked for new tasks; tasks that have it keep showing it
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['phase', 'sort']);
        });

        $now = now();
        $rows = [];
        foreach (self::DEFAULT_STAGES as $phase => $names) {
            foreach ($names as $i => $name) {
                $rows[] = ['phase' => $phase, 'name' => $name, 'sort' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('pipeline_stages')->insert($rows);

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('sub_project_id')->constrained('pipeline_stages')->nullOnDelete();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('kind', ['internal', 'client_review', 'delivery'])->default('internal');
            // Studio calendar date (Asia/Jakarta)
            $table->date('due_date');
            $table->dateTime('done_at', 3)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'due_date']);
        });

        // Budgets are entered in hours and stored in minutes
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('budget_minutes')->nullable()->after('description');
        });

        Schema::table('sub_projects', function (Blueprint $table) {
            $table->unsignedInteger('budget_minutes')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('sub_projects', function (Blueprint $table) {
            $table->dropColumn('budget_minutes');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('budget_minutes');
        });
        Schema::dropIfExists('project_milestones');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
        });
        Schema::dropIfExists('pipeline_stages');
    }
};
