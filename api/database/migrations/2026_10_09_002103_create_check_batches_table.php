<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('check_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->string('status', 20)->default('processing');
            $table->string('filename')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->json('options')->nullable();
            $table->unsignedBigInteger('seq')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_batches');
    }
};
