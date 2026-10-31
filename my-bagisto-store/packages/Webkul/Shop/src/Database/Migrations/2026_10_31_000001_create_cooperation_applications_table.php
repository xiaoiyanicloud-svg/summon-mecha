<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cooperation_applications')) {
            return;
        }

        Schema::create('cooperation_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no', 40)->unique('uk_cooperation_application_no');
            $table->unsignedInteger('customer_id')->nullable()->index('idx_coop_app_customer');
            $table->string('guest_key', 64)->nullable()->index('idx_coop_app_guest_key');
            $table->string('coop_type', 32)->index('idx_coop_app_type');
            $table->string('org_name', 191);
            $table->string('contact_name', 64);
            $table->string('contact_phone', 32)->index('idx_coop_app_phone');
            $table->string('contact_wechat', 128)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('coop_direction', 191)->nullable();
            $table->text('summary')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 32)->default('pending')->index('idx_coop_app_status');
            $table->text('admin_note')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperation_applications');
    }
};
