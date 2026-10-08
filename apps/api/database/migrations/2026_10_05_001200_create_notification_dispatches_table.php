<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 80);
            $table->string('event_key', 160);
            $table->string('recipient_email');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['organisation_id', 'type', 'event_key', 'recipient_email'],
                'notification_dispatches_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
