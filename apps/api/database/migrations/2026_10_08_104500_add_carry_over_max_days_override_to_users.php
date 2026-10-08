<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'carry_over_max_days_override')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('carry_over_max_days_override')
                    ->nullable()
                    ->after('carry_over_override');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'carry_over_max_days_override')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('carry_over_max_days_override');
            });
        }
    }
};
