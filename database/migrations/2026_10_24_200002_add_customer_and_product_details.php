<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('payment_status');
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->string('customer_phone', 32)->nullable()->after('customer_email');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('image_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_email', 'customer_phone']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['description', 'image_url']);
        });
    }
};
