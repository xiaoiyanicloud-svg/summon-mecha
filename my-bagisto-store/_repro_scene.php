<?php
// 复现: 后台场景管理上传图片。构造真实 multipart 上传,走完整 store 链路。
// 用法: D:/php.exe _repro_scene.php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\File\UploadedFile;

function makePng() {
    $tmp = tempnam(sys_get_temp_dir(), 'scn').'.png';
    $im = imagecreatetruecolor(120, 80);
    imagefilledrectangle($im, 0, 0, 119, 79, imagecolorallocate($im, 255, 122, 32));
    imagepng($im, $tmp);
    imagedestroy($im);
    return $tmp;
}

function runStore($app, $label, $preexistingId = null) {
    $tmp = makePng();
    $up = new UploadedFile($tmp, 'test.png', 'image/png', null, true);
    $files = ['banner_path' => [0 => $up]];
    $input = ['name' => '复现场景', 'description' => 'test', 'status' => '1'];
    if ($preexistingId) {
        $input['banner_path'] = ['banner_path' => '']; // 编辑时保留旧图的隐藏字段
    }
    $request = Request::create('admin/catalog/scenes/create', 'POST', $input, [], $files, [], '');
    $request->headers->set('Content-Type', 'multipart/form-data');
    $app->instance('request', $request);
    // 关键是让 request() helper 和容器都拿到同一个
    $request->setMethod('POST');

    $controller = $app->make(\Webkul\Admin\Http\Controllers\Catalog\SceneController::class);
    $sceneReq = $app->make(\Webkul\Admin\Http\Requests\SceneRequest::class);

    echo "\n===== [$label] =====\n";
    echo 'files->get("banner_path"): '; var_export($request->files->get('banner_path')); echo "\n";
    echo 'all(): '; var_export($request->all()); echo "\n";
    echo 'validator data banner_path: '; var_export($sceneReq->validationData()['banner_path'] ?? 'NULL'); echo "\n";

    $validator = $sceneReq->getValidatorInstance();
    echo 'validator passes: '.($validator->passes() ? 'PASS' : 'FAIL')."\n";
    echo 'validator failed: '.json_encode($validator->failed(), JSON_UNESCAPED_UNICODE)."\n";
    echo 'validator errors: '.$validator->errors()."\n";

    try {
        if ($preexistingId) {
            $ret = $controller->update($sceneReq, $preexistingId);
        } else {
            $ret = $controller->store($sceneReq);
        }
        echo 'RESULT: '.($ret ? 'redirect->'.$ret->getTargetUrl() : 'null')."\n";
    } catch (\Throwable $e) {
        echo 'EXCEPTION['.get_class($e).']: '.$e->getMessage()."\n";
    }
    @unlink($tmp);
}

echo 'PHP '.PHP_VERSION."\n";

// 1) 新建带图
runStore($app, 'CREATE with image', null);

// 2) 编辑(带新图 + 隐藏旧图字段)
runStore($app, 'UPDATE with new image + hidden keep field', null, null, 6);

echo "\n=== 之后 scenes 状态 ===\n";
\DB::table('scenes')->get(['id','name','banner_path'])->each(function ($r) {
    echo $r->id.' | '.$r->name.' | banner='.var_export($r->banner_path, true)."\n";
});
