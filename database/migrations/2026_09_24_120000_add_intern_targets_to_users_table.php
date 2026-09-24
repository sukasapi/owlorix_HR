<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-person weekly target of an intern (docs/02 3.12). Null uses the Superadmin default on Aturan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('intern_days_per_week')->nullable()->after('employment_type');
            $table->unsignedSmallInteger('intern_minutes_per_day')->nullable()->after('intern_days_per_week');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['intern_days_per_week', 'intern_minutes_per_day']);
        });
    }
};
