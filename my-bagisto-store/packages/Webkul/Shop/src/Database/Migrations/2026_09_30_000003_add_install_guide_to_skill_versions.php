<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技能版本增加「安装指引」。
 *
 * 背景:第三方成品机器人改不了固件,厂家也没有面向第三方平台的分发接口
 * (宇树的 UniStore 是"用户在宇树 App 里点一下、机器人云拉执行",那一步第三方拿不到)。
 * 所以在厂家通道打通之前,平台的兜底交付形态是:
 *
 *   平台交付文件 → 用户按指引把文件装进机器人 → 用户自报"已安装"
 *
 * 指引跟的是技能版本(一个版本一份说明),而不是机型 —— 因为它描述的是
 * "这个版本的技能包怎么导入",随技能内容变化;机型差异写在指引正文里即可。
 *
 * 放在 skill_versions 而不是 skill_assets:它是一个版本的属性,不是交付物;
 * 且不落盘、不进 private 盘,只是文本。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('skill_versions', 'install_guide')) {
            return;
        }

        Schema::table('skill_versions', function (Blueprint $table) {
            $table->text('install_guide')->nullable()->after('changelog');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('skill_versions', 'install_guide')) {
            return;
        }

        Schema::table('skill_versions', function (Blueprint $table) {
            $table->dropColumn('install_guide');
        });
    }
};
