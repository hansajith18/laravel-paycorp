<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('operation', 30); // PAYMENT_INIT, PAYMENT_COMPLETE
            $table->string('direction', 10); // request, response

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('payload'); // sanitized - never contains raw card data
            $table->json('raw_response')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_logs');
    }
};
