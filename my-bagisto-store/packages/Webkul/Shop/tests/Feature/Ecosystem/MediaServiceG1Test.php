<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Services\Ecosystem\MediaService;
use Webkul\Shop\Services\Ecosystem\MediaTransitionException;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;

/**
 * G1-06 · MediaService 上传与审核（ADR-0001 §4.9 / G1-D06）。
 *
 * 保护的是**真实字节**，不是客户端声明：
 *   · 真实 MIME 用 finfo 解码；PHP / SVG / GIF 伪装成图片一律拒绝；
 *   · 扩展名必须与真实 MIME 匹配；
 *   · 图片 getimagesize 解码 + 尺寸上限；PDF 头部验证；
 *   · 图片 5MB / 文档 10MB；
 *   · 路径由服务端按 sha256 生成，原始文件名净化目录与控制字符；
 *   · sha256 全局唯一，重复内容不重复落盘；
 *   · 私有盘落盘，无公开 URL；
 *   · pending -> approved / rejected，pending|approved -> quarantined。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-06 media service tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');
});

function g6SvcService(): MediaService
{
    return app(MediaService::class);
}

function g6SvcAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G6 Media Admin',
        'email'      => 'g6-media-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g6SvcPng(string $name = 'cover.png', int $width = 320, int $height = 240): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function g6SvcRaw(string $content, string $name, string $clientMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'g6raw_');

    if ($path === false) {
        throw new RuntimeException('无法创建测试临时文件。');
    }

    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $clientMime, null, true);
}

function g6SvcPdf(string $name = 'rules.pdf', ?string $content = null): UploadedFile
{
    return g6SvcRaw(
        $content ?? "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        $name,
        'application/pdf',
    );
}

/**
 * 建一条真实 draft content revision 作为媒体引用归属。
 *
 * 引用模型的守卫会查父 revision 是否存在且为 draft，因此测试不能再拿
 * 不存在的 owner_id 当夹具 —— 那会让“枚举校验”用例被父级校验抢先命中。
 */
function g6SvcDraftContentRevisionId(): int
{
    $postId = (int) DB::table('content_posts')->insertGetId([
        'type'       => 'news',
        'slug'       => 'g6-svc-'.bin2hex(random_bytes(6)),
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('content_revisions')->insertGetId([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => 'G6 媒体引用夹具',
        'author_name'         => 'G6 作者',
        'source_type'         => 'original',
        'change_summary'      => '媒体引用夹具',
        'content_fingerprint' => str_repeat('c', 64),
        'sanitizer_version'   => 'unsanitized-g1-05',
        'review_state'        => 'draft',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

it('G1-06 · 上传真实 PNG：真实 MIME / 尺寸 / sha256 / 私有路径 / pending', function () {
    $adminId = g6SvcAdminId();
    $file = g6SvcPng();
    $expectedSha = hash_file('sha256', $file->getRealPath());

    $media = g6SvcService()->upload($file, $adminId);

    $row = DB::table('ecosystem_media')->where('id', $media->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->disk)->toBe('local')
        ->and($row->mime)->toBe('image/png')
        ->and((int) $row->byte_size)->toBe((int) $file->getSize())
        ->and((int) $row->width)->toBe(320)
        ->and((int) $row->height)->toBe(240)
        ->and($row->sha256)->toBe($expectedSha)
        ->and($row->status)->toBe('pending')
        ->and((int) $row->uploaded_by)->toBe($adminId)
        ->and($row->original_name)->toBe('cover.png')
        ->and($row->storage_key)->toMatch('/^ecosystem\/media\/[0-9a-f]{2}\/[0-9a-f]{64}\.png$/');

    // 私有盘落盘；磁盘配置没有公开 URL / public visibility，模型也不提供公开 URL。
    // 注意：Storage::fake('local') 会把 fake 文件标成 public，不能拿 getVisibility 当私有性证据。
    Storage::disk('local')->assertExists($row->storage_key);
    expect(config('filesystems.disks.local.url'))->toBeNull()
        ->and(config('filesystems.disks.local.visibility'))->not->toBe('public')
        ->and(array_key_exists('public_url', $media->getAttributes()))->toBeFalse()
        ->and(method_exists($media, 'publicUrl'))->toBeFalse();
});

it('G1-06 · 上传真实 PDF：尺寸为 NULL，落 .pdf 私有路径', function () {
    $adminId = g6SvcAdminId();

    $media = g6SvcService()->upload(g6SvcPdf(), $adminId);

    $row = DB::table('ecosystem_media')->where('id', $media->getKey())->first();

    expect($row->mime)->toBe('application/pdf')
        ->and($row->width)->toBeNull()
        ->and($row->height)->toBeNull()
        ->and($row->status)->toBe('pending')
        ->and($row->storage_key)->toMatch('/^ecosystem\/media\/[0-9a-f]{2}\/[0-9a-f]{64}\.pdf$/');

    Storage::disk('local')->assertExists($row->storage_key);
});

it('G1-06 · 拒绝 MIME 伪造：PHP 内容改名为 .png 仍按真实 MIME 拒绝', function () {
    $adminId = g6SvcAdminId();

    $forged = g6SvcRaw("<?php echo 'pwned'; ?>\n", 'evil.png', 'image/png');

    try {
        g6SvcService()->upload($forged, $adminId);
        $this->fail('伪造 MIME 的文件应被拒绝。');
    } catch (MediaValidationException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }

    expect(DB::table('ecosystem_media')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('G1-06 · 拒绝 SVG / GIF / PHP 扩展名与 PNG 内容不匹配', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();

    // 先持有 PNG 对象再读字节：fake image 基于 tmpfile 资源，对象被回收后文件即关闭。
    $png = g6SvcPng();
    $pngBytes = file_get_contents($png->getRealPath());

    $cases = [
        g6SvcRaw('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'logo.svg', 'image/svg+xml'),
        UploadedFile::fake()->image('anim.gif', 20, 20),
        // 真实 PNG 字节但扩展名是 .jpg —— 扩展名与 MIME 不匹配。
        g6SvcRaw($pngBytes, 'cover.jpg', 'image/jpeg'),
        g6SvcRaw("<?php echo 1; ?>\n", 'shell.php', 'text/x-php'),
    ];

    foreach ($cases as $file) {
        $thrown = null;

        try {
            $service->upload($file, $adminId);
        } catch (MediaValidationException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull(sprintf('文件「%s」应被拒绝。', $file->getClientOriginalName()))
            ->and($thrown->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }

    expect(DB::table('ecosystem_media')->count())->toBe(0);
});

it('G1-06 · 图片 5MB / 文档 10MB 上限生效', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();

    config(['ecosystem_media.images.max_bytes' => 10]);

    expect(fn () => $service->upload(g6SvcPng(), $adminId))
        ->toThrow(MediaValidationException::class);

    config([
        'ecosystem_media.images.max_bytes' => 5 * 1024 * 1024,
        'ecosystem_media.docs.max_bytes'   => 10,
    ]);

    expect(fn () => $service->upload(g6SvcPdf(), $adminId))
        ->toThrow(MediaValidationException::class);

    expect(DB::table('ecosystem_media')->count())->toBe(0);
});

it('G1-06 · 图片单边尺寸超过 8000px 上限时拒绝', function () {
    config(['ecosystem_media.max_dimension' => 100]);

    expect(fn () => g6SvcService()->upload(g6SvcPng('wide.png', 320, 240), g6SvcAdminId()))
        ->toThrow(MediaValidationException::class);

    expect(DB::table('ecosystem_media')->count())->toBe(0);
});

it('G1-06 · 原始文件名净化目录与控制字符，路径只由服务端生成', function () {
    $adminId = g6SvcAdminId();

    $media = g6SvcService()->upload(g6SvcPng("../../evil\x01 name.png"), $adminId);

    expect($media->original_name)->toBe('evil name.png')
        ->and($media->storage_key)->toStartWith('ecosystem/media/')
        ->and($media->storage_key)->not->toContain('..')
        ->and($media->storage_key)->not->toContain('evil');

    Storage::disk('local')->assertExists($media->storage_key);
});

it('G1-06 · 相同 sha256 内容拒绝重复上传且不重复落盘', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();

    $first = g6SvcPng();
    $service->upload($first, $adminId);
    $filesAfterFirst = Storage::disk('local')->allFiles();

    $duplicate = g6SvcRaw(
        file_get_contents($first->getRealPath()),
        'copy.png',
        'image/png',
    );

    try {
        $service->upload($duplicate, $adminId);
        $this->fail('重复 sha256 应被拒绝。');
    } catch (MediaValidationException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }

    expect(DB::table('ecosystem_media')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe($filesAfterFirst);
});

it('G1-06 · 无效后台账号拒绝上传与审核', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();

    expect(fn () => $service->upload(g6SvcPng(), 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->upload(g6SvcPng(), 999999999))
        ->toThrow(InvalidArgumentException::class);

    $media = $service->upload(g6SvcPng(), $adminId);

    expect(fn () => $service->approve($media, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->approve($media, 999999999))
        ->toThrow(InvalidArgumentException::class);
});

it('G1-06 · 审核状态机：pending -> approved，approved 不能重批 / 拒绝，可隔离', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();
    $media = $service->upload(g6SvcPng(), $adminId);

    $approved = $service->approve($media, $adminId);

    expect($approved->status)->toBe('approved')
        ->and(DB::table('ecosystem_media')->where('id', $media->getKey())->value('status'))->toBe('approved');

    expect(fn () => $service->approve($media->refresh(), $adminId))
        ->toThrow(MediaTransitionException::class);

    expect(fn () => $service->reject($media->refresh(), $adminId))
        ->toThrow(MediaTransitionException::class);

    $quarantined = $service->quarantine($media->refresh(), $adminId);

    expect($quarantined->status)->toBe('quarantined');

    // 已隔离不能原地恢复为 approved。
    expect(fn () => $service->approve($media->refresh(), $adminId))
        ->toThrow(MediaTransitionException::class);
});

it('G1-06 · pending -> rejected；已拒绝不能批准或隔离', function () {
    $service = g6SvcService();
    $adminId = g6SvcAdminId();
    $media = $service->upload(g6SvcPng(), $adminId);

    $rejected = $service->reject($media, $adminId);

    expect($rejected->status)->toBe('rejected');

    expect(fn () => $service->approve($media->refresh(), $adminId))
        ->toThrow(MediaTransitionException::class);

    expect(fn () => $service->quarantine($media->refresh(), $adminId))
        ->toThrow(MediaTransitionException::class);
});

it('G1-06 · 媒体物理身份列创建后不可原位替换', function () {
    $media = g6SvcService()->upload(g6SvcPng(), g6SvcAdminId());

    foreach (['storage_key', 'sha256', 'disk', 'mime', 'byte_size', 'width', 'height'] as $field) {
        $model = EcosystemMedia::query()->findOrFail($media->getKey());
        $model->{$field} = $field === 'byte_size' ? 1 : 'tampered';

        expect(fn () => $model->save())
            ->toThrow(MediaValidationException::class);
    }
});

it('G1-06 · MediaRef 只接受注册表内的 owner_type / role / review_status', function () {
    $media = g6SvcService()->upload(g6SvcPng(), g6SvcAdminId());
    $revisionId = g6SvcDraftContentRevisionId();

    foreach ([
        ['owner_type' => 'event', 'role' => 'cover', 'review_status' => 'pending'],
        ['owner_type' => 'event_revision', 'role' => 'banner', 'review_status' => 'pending'],
        ['owner_type' => 'event_revision', 'role' => 'cover', 'review_status' => 'quarantined'],
    ] as $index => $attributes) {
        expect(fn () => EcosystemMediaRef::create(array_merge([
            'media_id'   => (int) $media->getKey(),
            'owner_id'   => $revisionId,
            'sort_order' => $index,
        ], $attributes)))->toThrow(MediaValidationException::class);
    }

    // 省略 review_status 时模型补默认 pending；合法 owner_type 可写入。
    $ref = EcosystemMediaRef::create([
        'media_id'   => (int) $media->getKey(),
        'owner_type' => 'content_revision',
        'owner_id'   => $revisionId,
        'role'       => 'cover',
        'sort_order' => 0,
    ]);

    expect($ref->review_status)->toBe('pending')
        ->and(DB::table('ecosystem_media_refs')->where('id', $ref->getKey())->value('review_status'))
        ->toBe('pending');
});

it('G1-06 · 媒体配置是唯一事实源', function () {
    expect(config('ecosystem_media.disk'))->toBe('local')
        ->and(config('ecosystem_media.allowed_mimes'))->toBe([
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ])
        ->and(config('ecosystem_media.images.max_bytes'))->toBe(5 * 1024 * 1024)
        ->and(config('ecosystem_media.docs.max_bytes'))->toBe(10 * 1024 * 1024)
        ->and(config('ecosystem_media.max_dimension'))->toBe(8000)
        ->and(config('ecosystem_media.max_refs_per_revision'))->toBe(20)
        ->and(config('ecosystem_media.roles'))->toBe(['cover', 'inline', 'attachment', 'rule_document'])
        ->and(config('ecosystem_media.statuses'))->toBe(['pending', 'approved', 'rejected', 'quarantined'])
        ->and(config('ecosystem_media.review_statuses'))->toBe(['pending', 'approved', 'rejected']);
});
