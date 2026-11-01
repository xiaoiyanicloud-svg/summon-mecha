<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_orders')) {
            Schema::table('merchant_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('merchant_orders', 'cancel_reason')) {
                    $table->string('cancel_reason', 255)->nullable()->after('status');
                }
                if (! Schema::hasColumn('merchant_orders', 'cancel_party')) {
                    $table->string('cancel_party', 32)->nullable()->after('cancel_reason'); // buyer | merchant | system | platform
                }
                if (! Schema::hasColumn('merchant_orders', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancel_party');
                }
                if (! Schema::hasColumn('merchant_orders', 'buyer_id')) {
                    $table->unsignedBigInteger('buyer_id')->nullable()->index()->after('buyer_phone');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_orders')) {
            Schema::table('merchant_orders', function (Blueprint $table) {
                if (Schema::hasColumn('merchant_orders', 'cancel_reason')) {
                    $table->dropColumn('cancel_reason');
                }
                if (Schema::hasColumn('merchant_orders', 'cancel_party')) {
                    $table->dropColumn('cancel_party');
                }
                if (Schema::hasColumn('merchant_orders', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
                if (Schema::hasColumn('merchant_orders', 'buyer_id')) {
                    $table->dropColumn('buyer_id');
                }
            });
        }
    }
};
