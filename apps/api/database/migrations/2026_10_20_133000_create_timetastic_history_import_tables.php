<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timetastic_imports')) {
            Schema::create('timetastic_imports', function (Blueprint $table) {
                $table->id();
                $table->string('booking_id')->unique();
                $table->foreignId('leave_request_id')
                    ->nullable()
                    ->constrained('leave_requests')
                    ->nullOnDelete();
                $table->json('report_years')->nullable();
                $table->json('source_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('timetastic_allowance_snapshots')) {
            Schema::create('timetastic_allowance_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organisation_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
                $table->unsignedBigInteger('timetastic_user_id')->nullable();
                $table->unsignedSmallInteger('year');
                $table->string('user_name');
                $table->string('email')->nullable();
                $table->decimal('allowance_days', 8, 2)->default(0);
                $table->decimal('brought_forward_days', 8, 2)->default(0);
                $table->decimal('time_in_lieu_days', 8, 2)->default(0);
                $table->decimal('taken_days', 8, 2)->default(0);
                $table->decimal('carried_into_next_year_days', 8, 2)->default(0);
                $table->decimal('remaining_days', 8, 2)->default(0);
                $table->decimal('holiday_days', 8, 2)->default(0);
                $table->timestamps();

                $table->unique(
                    ['organisation_id', 'timetastic_user_id', 'year'],
                    'timetastic_allowance_snapshot_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timetastic_allowance_snapshots');
        Schema::dropIfExists('timetastic_imports');
    }
};
