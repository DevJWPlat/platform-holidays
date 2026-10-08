<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holiday_balance_reminder_dispatches')) {
            return;
        }

        Schema::create(
            'holiday_balance_reminder_dispatches',
            function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->date('leave_year_start');
                $table->unsignedTinyInteger('months_remaining');
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique([
                    'user_id',
                    'leave_year_start',
                    'months_remaining',
                ], 'holiday_balance_reminder_unique');
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'holiday_balance_reminder_dispatches',
        );
    }
};
