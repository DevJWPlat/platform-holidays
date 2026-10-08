<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->string('start_session', 20);
            $table->date('ends_on');
            $table->string('end_session', 20);
            $table->unsignedInteger('duration_minutes');
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
