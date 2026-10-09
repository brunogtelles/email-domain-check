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
        Schema::create('check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('check_batch_id')->constrained()->cascadeOnDelete();
            $table->string('input', 320);
            $table->string('domain', 253)->nullable();
            $table->string('selector', 100)->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('seq')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['check_batch_id', 'seq']);
            $table->index(['check_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_items');
    }
};
