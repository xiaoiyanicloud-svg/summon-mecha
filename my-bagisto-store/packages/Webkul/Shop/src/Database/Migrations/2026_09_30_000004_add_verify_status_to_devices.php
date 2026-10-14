<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 设备增加「核验状态」—— 把「登记」和「绑定」区分开。
 *
 * 起因:原先的 devices 只有 bind_status(bound/revoked),语义上等于"已经绑定了这台机器"。
 * 但实际上平台**没有任何渠道验证这台机器真实存在** —— 用户填什么 SN 都算数。
 * 把这种状态叫「绑定」是过度承诺,用户会以为平台真的连上了他的机器人。
 *
 * 所以拆成两根正交的轴:
 *   bind_status   生命周期:bound(在册) / revoked(已解绑)   —— 一直都有
 *   verify_status 核验程度:registered(用户自报,平台未核验) / verified(经厂商通道确认)
 *
 * verified 现在永远是空的 —— 要等厂商开放设备接口(参考宇树/智元那种语音播报验证码、
 * 由厂商云校验的机制)。列先留着,接口通了只需要写这一列,不用再改表。
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
        if (Schema::hasColumn('devices', 'verify_status')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            // registered = 用户自报,平台未核验;verified = 经厂商通道确认
            $table->string('verify_status', 16)->default('registered')->after('bind_status');
            $table->timestamp('verified_at')->nullable()->after('verify_status');
            // 预留:将来是哪个通道核验的(如 unitree / agibot),便于多厂商并存时追溯
            $table->string('verified_via', 32)->nullable()->after('verified_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('devices', 'verify_status')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['verify_status', 'verified_at', 'verified_via']);
        });
    }
};
