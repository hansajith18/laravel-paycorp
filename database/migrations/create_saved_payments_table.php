<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 30)->default('paycorp');
            $table->string('token')->index();
            $table->string('card_type', 20)->nullable();
            $table->string('masked_card_number', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_expiry', 10)->nullable();
            $table->string('card_holder_name')->nullable();
            $table->string('token_reference')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_payments');
    }
};
