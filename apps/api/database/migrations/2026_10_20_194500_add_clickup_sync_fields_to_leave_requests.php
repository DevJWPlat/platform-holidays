<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('clickup_task_id')->nullable()->after('cancelled_at');
            $table->timestamp('clickup_synced_at')->nullable()->after('clickup_task_id');
            $table->text('clickup_sync_error')->nullable()->after('clickup_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'clickup_task_id',
                'clickup_synced_at',
                'clickup_sync_error',
            ]);
        });
    }
};
