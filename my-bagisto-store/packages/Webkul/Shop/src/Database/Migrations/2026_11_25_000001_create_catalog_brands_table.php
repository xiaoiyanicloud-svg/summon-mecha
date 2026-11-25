<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_brands')) {
            return;
        }

        Schema::create('catalog_brands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->json('aliases')->nullable();
            $table->string('device_brand', 64)->nullable()->index();
            $table->string('logo_path', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('official_url', 255)->nullable();
            $table->boolean('is_hot')->default(false)->index();
            $table->boolean('status')->default(true)->index();
            $table->integer('sort_order')->default(100)->index();
            $table->unsignedInteger('attribute_option_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_brands');
    }
};
