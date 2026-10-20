<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('customer_id');
                $table->string('client_id', 64);
                $table->string('title', 191)->default('');
                $table->longText('messages')->nullable();
                $table->unsignedBigInteger('client_updated_at');
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['customer_id', 'client_id'], 'uk_ai_conversations_customer_client');
                $table->index(['customer_id', 'client_updated_at'], 'idx_ai_conversations_customer_updated');
                $table->index(['customer_id', 'deleted_at'], 'idx_ai_conversations_customer_deleted');
                $table->foreign('customer_id', 'fk_ai_conversations_customer')
                    ->references('id')->on('customers')
                    ->onDelete('cascade')->onUpdate('restrict');
            });
        }

        if (! Schema::hasTable('ai_analytics_events')) {
            Schema::create('ai_analytics_events', function (Blueprint $table): void {
                $table->id();
                $table->string('event_id', 64)->unique('uk_ai_analytics_event_id');
                $table->unsignedInteger('customer_id')->nullable();
                $table->string('device_hash', 64)->nullable();
                $table->string('event_name', 64);
                $table->longText('properties')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->nullable();

                $table->index(['event_name', 'occurred_at'], 'idx_ai_analytics_name_time');
                $table->index(['customer_id', 'occurred_at'], 'idx_ai_analytics_customer_time');
                $table->foreign('customer_id', 'fk_ai_analytics_customer')
                    ->references('id')->on('customers')
                    ->onDelete('set null')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analytics_events');
        Schema::dropIfExists('ai_conversations');
    }
};
