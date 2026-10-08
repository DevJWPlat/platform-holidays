<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_override_staffing_limits')
                ->default(false)
                ->after('is_archived');
        });

        Schema::create('staffing_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('maximum_absent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organisation_id', 'slug']);
        });

        Schema::create('staffing_group_user', function (Blueprint $table) {
            $table->foreignId('staffing_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['staffing_group_id', 'user_id']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('reason');
            $table->boolean('staffing_override')->default(false)->after('is_manual');
            $table->foreignId('staffing_override_by')
                ->nullable()
                ->after('staffing_override')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('staffing_override_reason')
                ->nullable()
                ->after('staffing_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staffing_override_by');
            $table->dropColumn([
                'staffing_override_reason',
                'staffing_override',
                'is_manual',
            ]);
        });

        Schema::dropIfExists('staffing_group_user');
        Schema::dropIfExists('staffing_groups');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_override_staffing_limits');
        });
    }
};
