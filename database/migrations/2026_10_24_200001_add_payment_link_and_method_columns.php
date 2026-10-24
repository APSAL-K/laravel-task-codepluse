<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_link_id')->nullable()->index()->after('razorpay_payment_id');
            $table->string('payment_link_url')->nullable()->after('payment_link_id');
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('method')->nullable()->after('status');
            $table->string('method_detail')->nullable()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_link_id', 'payment_link_url']);
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['method', 'method_detail']);
        });
    }
};
