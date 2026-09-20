<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a proposal was applied or declined and the note of a decline (3.10). `approved_by` holds the Superadmin who
     * applied or declined it.
     */
    public function up(): void
    {
        Schema::table('corrections', function (Blueprint $table) {
            $table->dateTime('decided_at', 3)->nullable()->after('status');
            $table->text('decision_note')->nullable()->after('decided_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('corrections', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['decided_at', 'decision_note']);
        });
    }
};
