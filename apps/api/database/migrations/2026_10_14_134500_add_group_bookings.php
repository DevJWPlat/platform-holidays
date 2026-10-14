<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('scope_type', 30);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('start_session', 20)->default('morning');
            $table->string('end_session', 20)->default('afternoon');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('group_booking_id')
                ->nullable()
                ->after('leave_type_id')
                ->constrained('group_bookings')
                ->nullOnDelete();
        });

        DB::table('organisation_settings')
            ->where('key', 'company_closures')
            ->delete();
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_booking_id');
        });
        Schema::dropIfExists('group_bookings');
    }
};
