<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 赛事活动平台级闭环:
 *  events                 —— 赛事(后台「赛事管理」维护,EcoCenter 赛事 tab 展示)
 *  event_registrations    —— 用户报名(闭环关键:前台报名落库,后台可查)
 * 回滚: down() 按依赖倒序删表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('格斗竞技');
            $table->string('status', 20)->default('registering'); // registering|ongoing|upcoming|ended
            $table->string('date')->nullable();
            $table->string('city')->nullable();
            $table->string('location')->nullable();
            $table->string('organizer')->nullable();
            $table->unsignedInteger('hot')->default(60);
            $table->unsignedInteger('teams')->default(0);
            $table->text('prize')->nullable();
            $table->text('schedule')->nullable();
            $table->text('history')->nullable();
            $table->text('detail')->nullable();
            $table->string('icon')->default('🏆');
            $table->string('bg_color', 20)->default('#E8F0FE');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_id')->index();
            $table->unsignedInteger('customer_id')->nullable()->index();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('city')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
