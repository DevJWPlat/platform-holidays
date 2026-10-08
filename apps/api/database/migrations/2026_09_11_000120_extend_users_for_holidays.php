<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organisation_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->string('job_title')->nullable()->after('email');
            $table->string('avatar_url')->nullable()->after('job_title');

            $table->string('role')
                ->default('employee')
                ->after('avatar_url');

            $table->date('employment_start_date')->nullable()->after('role');
            $table->date('leaving_date')->nullable()->after('employment_start_date');
            $table->date('date_of_birth')->nullable()->after('leaving_date');

            $table->string('allowance_unit')
                ->default('days')
                ->after('date_of_birth');

            $table->string('bank_holiday_division')
                ->nullable()
                ->after('allowance_unit');

            $table->boolean('is_archived')
                ->default(false)
                ->after('bank_holiday_division');

            $table->timestamp('archived_at')->nullable()->after('is_archived');

            $table->index(['organisation_id', 'is_archived']);
            $table->index(['organisation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->dropIndex(['organisation_id', 'is_archived']);
            $table->dropIndex(['organisation_id', 'role']);

            $table->dropColumn([
                'organisation_id',
                'job_title',
                'avatar_url',
                'role',
                'employment_start_date',
                'leaving_date',
                'date_of_birth',
                'allowance_unit',
                'bank_holiday_division',
                'is_archived',
                'archived_at',
            ]);
        });
    }
};
