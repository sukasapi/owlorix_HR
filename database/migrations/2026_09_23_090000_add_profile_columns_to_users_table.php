<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('name');
            $table->string('job_title', 80)->nullable()->after('employment_type');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('birth_place', 80)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('birth_place');
            $table->enum('gender', ['male', 'female'])->nullable()->after('birth_date');
            $table->text('address')->nullable()->after('gender');
            $table->string('emergency_contact_name', 120)->nullable()->after('address');
            $table->string('emergency_contact_relation', 60)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_relation');
            $table->string('bio', 500)->nullable()->after('emergency_contact_phone');
            $table->string('portfolio_url', 300)->nullable()->after('bio');
            $table->string('cv_path')->nullable()->after('avatar_path');
            $table->string('cv_original_name', 190)->nullable()->after('cv_path');
            $table->dateTime('cv_uploaded_at', 3)->nullable()->after('cv_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nickname', 'job_title', 'phone', 'birth_place', 'birth_date', 'gender', 'address',
                'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
                'bio', 'portfolio_url', 'cv_path', 'cv_original_name', 'cv_uploaded_at',
            ]);
        });
    }
};
