<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('external_source', 40)->nullable()->after('cancelled_at');
            $table->string('external_id', 100)->nullable()->after('external_source');
            $table->unique(
                ['external_source', 'external_id'],
                'leave_requests_external_source_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropUnique('leave_requests_external_source_id_unique');
            $table->dropColumn(['external_source', 'external_id']);
        });
    }
};
