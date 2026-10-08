<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approver_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('approver_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('priority')->default(1);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'employee_id']);
            $table->index(['organisation_id', 'department_id']);
            $table->index(['organisation_id', 'approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_assignments');
    }
};
