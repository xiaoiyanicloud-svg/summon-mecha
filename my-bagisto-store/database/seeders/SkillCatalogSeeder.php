<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 技能类目/标签种子(v3.4 步骤 6)。
 *
 * 类目来自计划 §4.21 的品类优先级:展演/接待/互动/生产力(谨慎)。
 * 标签按 type:motion_type(动作形态)/scene(场景)/difficulty(难度)。
 * 幂等:按 code/name 存在则更新。
 */
class SkillCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $categories = [
            ['code' => 'show',    'name' => ['zh_CN' => '展演', 'en' => 'Performance'], 'sort' => 10],
            ['code' => 'reception', 'name' => ['zh_CN' => '接待导览', 'en' => 'Reception'], 'sort' => 20],
            ['code' => 'interactive', 'name' => ['zh_CN' => '互动娱乐', 'en' => 'Interactive'], 'sort' => 30],
            ['code' => 'custom',  'name' => ['zh_CN' => '企业定制', 'en' => 'Custom'], 'sort' => 40],
            ['code' => 'productivity', 'name' => ['zh_CN' => '生产力(谨慎)', 'en' => 'Productivity'], 'sort' => 50],
        ];

        foreach ($categories as $c) {
            DB::table('skill_category')->updateOrInsert(
                ['code' => $c['code']],
                ['name' => json_encode($c['name'], JSON_UNESCAPED_UNICODE), 'sort_order' => $c['sort'], 'status' => 'active', 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $tags = [
            ['name' => '舞蹈', 'type' => 'motion_type'],
            ['name' => '武术', 'type' => 'motion_type'],
            ['name' => '迎宾', 'type' => 'motion_type'],
            ['name' => '合影pose', 'type' => 'motion_type'],
            ['name' => '讲解', 'type' => 'motion_type'],
            ['name' => '商场', 'type' => 'scene'],
            ['name' => '展会', 'type' => 'scene'],
            ['name' => '婚礼', 'type' => 'scene'],
            ['name' => '文旅景区', 'type' => 'scene'],
            ['name' => '入门', 'type' => 'difficulty'],
            ['name' => '进阶', 'type' => 'difficulty'],
            ['name' => '高难', 'type' => 'difficulty'],
        ];

        foreach ($tags as $t) {
            $exists = DB::table('skill_tag')->where('name', $t['name'])->where('type', $t['type'])->exists();
            if (! $exists) {
                DB::table('skill_tag')->insert(['name' => $t['name'], 'type' => $t['type'], 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
