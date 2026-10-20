<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_evidence', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('product_id')->nullable();
            $t->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $t->unsignedInteger('binding_id')->nullable();
            $t->foreign('binding_id')->references('id')->on('device_binding')->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('capability', 80);
            $t->string('level', 10);
            $t->text('scope');
            $t->string('source_reference', 255);
            $t->char('artifact_hash', 64);
            $t->unsignedInteger('verified_by');
            $t->dateTime('verified_at');
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'capability', 'expires_at'], 'scene_evidence_lookup');
        });
        Schema::create('scene_assessments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedInteger('request_revision_no');
            $t->char('input_hash', 64);
            $t->string('state', 30);
            $t->json('checks');
            $t->json('candidate_snapshot');
            $t->unsignedInteger('created_by');
            $t->dateTime('created_at');
            $t->index(['request_id', 'request_revision_no']);
        });
        Schema::create('scene_price_codes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('code', 80)->unique();
            $t->timestamps();
        });
        Schema::create('scene_price_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('price_code_id');
            $t->foreign('price_code_id')->references('id')->on('scene_price_codes')->restrictOnDelete();
            $t->string('code', 80);
            $t->unsignedInteger('version');
            $t->string('name', 255);
            $t->string('kind', 20);
            $t->unsignedInteger('product_id')->nullable();
            $t->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $t->string('unit', 30);
            $t->string('currency', 3);
            $t->unsignedBigInteger('unit_minor');
            $t->unsignedInteger('tax_bps');
            $t->string('charge_type', 20);
            $t->dateTime('valid_from');
            $t->dateTime('valid_until');
            $t->unsignedInteger('created_by');
            $t->dateTime('revoked_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['code', 'version']);
        });
        Schema::create('scene_quotes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('quote_no')->unique();
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedInteger('request_revision_no');
            $t->unsignedBigInteger('assessment_id');
            $t->foreign('assessment_id')->references('id')->on('scene_assessments')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 20)->default('draft');
            $t->string('currency', 3);
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('discount_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->unsignedBigInteger('deposit_minor');
            $t->json('terms_snapshot');
            $t->char('terms_hash', 64);
            $t->char('content_hash', 64);
            $t->json('schedule');
            $t->dateTime('expires_at');
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'version']);
        });
        Schema::create('scene_quote_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id');
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->unsignedBigInteger('price_item_id');
            $t->foreign('price_item_id')->references('id')->on('scene_price_items')->restrictOnDelete();
            $t->unsignedInteger('line_no');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('billing_units');
            $t->json('snapshot');
            $t->unsignedBigInteger('line_minor');
            $t->unique(['quote_id', 'line_no']);
        });
        Schema::create('scene_quote_acceptances', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id')->unique();
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->string('principal_scope', 100);
            $t->char('terms_hash', 64);
            $t->char('content_hash', 64);
            $t->dateTime('created_at');
        });
        Schema::create('scene_resources', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('resource_no', 80)->unique();
            $t->string('kind', 20);
            $t->unsignedInteger('product_id')->nullable();
            $t->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $t->unsignedInteger('binding_id')->nullable()->unique();
            $t->foreign('binding_id')->references('id')->on('device_binding')->restrictOnDelete();
            $t->string('external_reference', 255);
            $t->string('city', 100);
            $t->string('timezone', 80);
            $t->string('state', 20)->default('unverified');
            $t->unsignedInteger('row_version')->default(1);
            $t->unsignedInteger('verified_by')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->dateTime('verification_expires_at')->nullable();
            $t->text('state_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('scene_reservations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('resource_id');
            $t->foreign('resource_id')->references('id')->on('scene_resources')->restrictOnDelete();
            $t->unsignedBigInteger('quote_id');
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->uuid('hold_group');
            $t->string('state', 20);
            $t->dateTime('service_starts_at');
            $t->dateTime('service_ends_at');
            $t->dateTime('occupies_from');
            $t->dateTime('occupies_until');
            $t->dateTime('hold_expires_at')->nullable();
            $t->timestamps();
            $t->index(['resource_id', 'state', 'occupies_from', 'occupies_until'], 'scene_reservation_overlap');
            $t->index(['state', 'hold_expires_at']);
            $t->unique(['quote_id', 'resource_id', 'hold_group'], 'scene_reservation_group_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('报价、接受和资源占用必须保留；请使用停写回退。');
    }
};
