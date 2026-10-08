<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('pattern_type')->default('weekly');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'name']);
            $table->index(['organisation_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
