<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Document links on the project page (docs/16): folders and files that stay on Google Drive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('label', 80)->nullable();
            $table->string('url', 500);
            $table->string('note', 200)->nullable();
            $table->boolean('managers_only')->default(false);
            $table->unsignedInteger('position');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
    }
};
