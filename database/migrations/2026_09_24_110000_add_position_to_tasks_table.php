<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The order a lead sets for the tasks of one sub project (docs/13 4.7). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('priority');
            $table->index(['sub_project_id', 'position']);
        });

        // Existing tasks start in the order the list showed them until now: priority, then due date, then oldest
        DB::table('tasks')->distinct()->orderBy('sub_project_id')->pluck('sub_project_id')->each(function (int $subProjectId) {
            DB::table('tasks')
                ->where('sub_project_id', $subProjectId)
                ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
                ->orderByRaw('due_date is null, due_date')
                ->orderBy('id')
                ->pluck('id')
                ->each(fn (int $id, int $i) => DB::table('tasks')->where('id', $id)->update(['position' => $i + 1]));
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['sub_project_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
