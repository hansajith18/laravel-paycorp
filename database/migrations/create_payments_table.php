<?php

use Hansajith18\LaravelPaycorp\Enums\GatewayPaymentStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('purpose', 30);
            $table->string('status', 20)->default(GatewayPaymentStatusEnum::PENDING->value);
            $table->string('gateway', 30)->default('paycorp');
            $table->string('currency', 3)->default('LKR');
            $table->unsignedBigInteger('amount_cents');

            // Merchant-side reference (unique per payment attempt)
            $table->string('client_ref', 50)->unique();

            // Paycorp gateway fields
            $table->string('gateway_req_id')->nullable()->index();
            $table->string('gateway_txn_reference')->nullable()->index();
            $table->string('gateway_response_code', 10)->nullable();
            $table->string('gateway_response_text')->nullable();
            $table->string('gateway_auth_code', 20)->nullable();

            // Safe card info only (PCI: no full card number, no CVV, no full expiry)
            $table->string('card_type', 20)->nullable();
            $table->string('masked_card_number', 20)->nullable();
            $table->string('card_last4', 4)->nullable();

            // Hosted page URL and expiry
            $table->text('payment_page_url')->nullable();
            $table->timestamp('expire_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            // Polymorphic link to payable entity (Order, TaxiBooking, etc.)
            $table->nullableMorphs('payable');

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
