<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 场景方案内容（文案）收录。
 *
 * 只更新已存在的场景行，按 id 幂等写入 desc / 标签 / 痛点-解决 / 收益 / 子场景，
 * 不新增场景、不改机器人绑定、不碰报价与工作流数据。适用于新环境部署后
 * 让「场景方案详情」与本地一致：
 *
 *     php artisan db:seed --class=SceneContentSeeder
 */
class SceneContentSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/scene-content.json');

        if (! is_file($path)) {
            $this->command?->warn('未找到 scene-content.json，跳过场景方案内容收录。');

            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);

        if (! is_array($rows)) {
            $this->command?->error('scene-content.json 解析失败，请检查格式。');

            return;
        }

        $updated = [];
        $skipped = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id === 0 || ! DB::table('scenes')->where('id', $id)->exists()) {
                $skipped[] = $id;

                continue;
            }

            DB::table('scenes')->where('id', $id)->update([
                'name'          => $row['name'],
                'description'   => $row['description'],
                'icon'          => $row['icon'],
                'bg_color'      => $row['bg_color'],
                'tags'          => json_encode($row['tags'], JSON_UNESCAPED_UNICODE),
                'pains'         => json_encode($row['pains'], JSON_UNESCAPED_UNICODE),
                'benefits'      => json_encode($row['benefits'], JSON_UNESCAPED_UNICODE),
                'scheme_scenes' => json_encode($row['scheme_scenes'], JSON_UNESCAPED_UNICODE),
                'updated_at'    => now(),
            ]);

            $updated[] = $id;
        }

        $this->command?->info('已更新场景方案内容：'.implode(', ', $updated).(count($skipped) ? '；跳过不存在的 id：'.implode(', ', $skipped) : ''));
    }
}
