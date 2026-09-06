<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Shop\Models\Scene;

/**
 * 预置企业商用(TOB)4 个场景。
 *
 * id 与前端 pages/robot/index.vue 的 sceneProducts 中 category=enterprise 的场景 id 一一对应(6~9)。
 * 运行:php artisan db:seed --class=SceneSeeder
 */
class SceneSeeder extends Seeder
{
    /**
     * Run the database seeders.
     */
    public function run()
    {
        $scenes = [
            6 => [
                'name'        => '展厅展馆讲解接待',
                'category'    => 'enterprise',
                'description' => '面向展厅、展馆的机器人讲解与导览服务方案，支持迎宾讲解、点位导览与互动问答。',
            ],
            7 => [
                'name'        => '政务大厅迎宾导览',
                'category'    => 'enterprise',
                'description' => '面向政务大厅的机器人迎宾、导览与业务指引方案，支持办事指引、政策咨询问答。',
            ],
            8 => [
                'name'        => '酒店大堂服务',
                'category'    => 'enterprise',
                'description' => '面向酒店大堂的机器人迎宾、行李协助与客房服务方案，支持入住引导与楼层导航。',
            ],
            9 => [
                'name'        => '企业前台接待',
                'category'    => 'enterprise',
                'description' => '面向企业前台的机器人接待、访客管理与前台交互方案，支持访客登记、通知到人。',
            ],
        ];

        foreach ($scenes as $id => $data) {
            $scene = Scene::find($id) ?? new Scene;

            $scene->id          = $id;
            $scene->name        = $data['name'];
            $scene->category    = $data['category'];
            $scene->description = $data['description'];
            $scene->banner_path = null;
            $scene->status      = 1;

            $scene->save();
        }
    }
}
