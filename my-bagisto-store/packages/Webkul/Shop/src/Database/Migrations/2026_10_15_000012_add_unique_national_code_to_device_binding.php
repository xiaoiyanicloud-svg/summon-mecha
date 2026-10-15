<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-19 · `device_binding.national_code`(一机一码)补唯一约束。
 *
 * 该列自建表迁移(2026_10_13_000002)就存在,但**从未被任何代码写过**,
 * 也没有唯一约束 —— 于是"一机一码"只是注释里的一句话:同一个码可以录到任意多台设备上,
 * 而库不会吭一声。补录入接口(DeviceController)的同时,把这条不变量落到库上。
 *
 * ⚠ 列可空,且绝大多数设备没有码(NULL)。MySQL 唯一索引**不约束 NULL** ——
 *   多行 NULL 不冲突,正是我们要的(否则第二台没填码的设备就登记不进来)。
 *
 * ⚠ 应用层(DeviceController::validateNationalCode)已有一次显式查重,给出人话错误;
 *   本唯一键是**并发兜底** —— 两个请求同时登记同一个码时,应用层查重会同时判"没人占",
 *   唯一键是唯一能真正挡住的那种情况。这与 devices.sn 的 `uk_devices_owner_sn` 同一姿态。
 *
 * 加唯一键前本机已核:device_binding 无重复 national_code(全是 NULL)。
 * 若在有重复的库上跑,迁移会在建索引时直接失败 —— 刻意如此:宁可中止,也不静默丢数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_binding')) {
            return;
        }

        Schema::table('device_binding', function (Blueprint $table) {
            if (! Schema::hasIndex('device_binding', 'uk_device_binding_national_code')) {
                $table->unique('national_code', 'uk_device_binding_national_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('device_binding')) {
            return;
        }

        Schema::table('device_binding', function (Blueprint $table) {
            if (Schema::hasIndex('device_binding', 'uk_device_binding_national_code')) {
                $table->dropUnique('uk_device_binding_national_code');
            }
        });
    }
};
