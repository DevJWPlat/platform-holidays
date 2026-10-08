<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('leave_types', function (Blueprint $table) { $table->string('icon_colour',10)->default('white')->after('icon'); }); }
 public function down(): void { Schema::table('leave_types', function (Blueprint $table) { $table->dropColumn('icon_colour'); }); }
};
