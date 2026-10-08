<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('leave_year_start');
            $table->date('leave_year_end');
            $table->string('entry_type', 40);
            $table->integer('minutes');
            $table->date('effective_date');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('source_key')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'leave_year_start', 'leave_year_end'], 'allowance_user_year_index');
            $table->index(['reference_type', 'reference_id'], 'allowance_reference_index');
            $table->unique(['user_id', 'leave_year_start', 'source_key'], 'allowance_user_year_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_ledger_entries');
    }
};
