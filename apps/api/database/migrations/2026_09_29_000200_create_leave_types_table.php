<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->string('key');
            $table->string('colour', 7)->default('#9FD356');
            $table->string('icon')->default('umbrella-beach');

            $table->boolean('is_system')->default(false);
            $table->boolean('is_protected_holiday')->default(false);

            $table->string('visibility')->default('public');
            $table->boolean('requires_approval')->default(true);
            $table->boolean('include_in_staffing_limits')->default(true);

            $table->unsignedInteger('annual_usage_limit_minutes')->nullable();
            $table->string('external_availability')->default('out_of_office');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'key']);
            $table->index(['organisation_id', 'is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
