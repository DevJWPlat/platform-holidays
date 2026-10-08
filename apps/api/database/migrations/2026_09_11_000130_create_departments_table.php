<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('colour', 7)->nullable();
            $table->unsignedInteger('maximum_absent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
