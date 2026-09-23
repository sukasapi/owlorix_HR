<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cuti dan izin (docs/14 section 4)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->boolean('counts_against_quota')->default(false);
            // The request must carry a reason (alasan)
            $table->boolean('requires_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Defaults from docs/14 4.1; Superadmin adds, renames, and switches types off at /admin/cuti
        $types = [
            ['code' => 'annual', 'name' => 'Cuti tahunan', 'counts_against_quota' => true, 'requires_note' => false],
            ['code' => 'sick', 'name' => 'Sakit', 'counts_against_quota' => false, 'requires_note' => true],
            ['code' => 'permission', 'name' => 'Izin', 'counts_against_quota' => false, 'requires_note' => true],
            ['code' => 'special', 'name' => 'Cuti khusus (menikah, duka, melahirkan)', 'counts_against_quota' => false, 'requires_note' => true],
            ['code' => 'unpaid', 'name' => 'Cuti tanpa upah', 'counts_against_quota' => false, 'requires_note' => true],
        ];

        $now = now();
        DB::table('leave_types')->insert(array_map(fn (array $type, int $i) => [
            ...$type,
            'is_active' => true,
            'sort' => ($i + 1) * 10,
            'created_at' => $now,
            'updated_at' => $now,
        ], $types, array_keys($types)));

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            // Studio calendar dates (Asia/Jakarta), both inclusive
            $table->date('start_date');
            $table->date('end_date');
            // Workdays in the range for this person, counted by the server with WorkdayResolver
            $table->unsignedSmallInteger('days');
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name', 180)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at', 3)->nullable();
            $table->text('decision_note')->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            // Who cancelled and why: the owner, or Superadmin with a note
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_note')->nullable();
            $table->timestamps(3);

            $table->index(['user_id', 'start_date']);
            $table->index(['status', 'start_date']);
        });

        Schema::create('leave_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('days');
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_quotas');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
