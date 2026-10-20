<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Scenes\SceneAttachmentRetentionService;
use Webkul\Shop\Services\Scenes\SceneWorkflowException;

class SceneAttachmentRetention extends Command
{
    protected $signature = 'scenes:attachments {id? : 私有附件 ID} {--apply : 实际清理一个已到期且无保全的原件} {--policy-version= : 当前已批准保留政策版本}';

    protected $description = '默认只读检查私有附件保留状态，显式 apply 才清理原件并留审计';

    public function handle(SceneAttachmentRetentionService $service): int
    {
        $id = $this->argument('id');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1 || strlen((string) $id) > 15)) {
            $this->error('附件 ID 无效。');

            return self::INVALID;
        }
        if ($this->option('apply') && (! $id || ! $this->option('policy-version'))) {
            $this->error('实际清理须指定单个附件及批准政策版本。');

            return self::INVALID;
        }
        try {
            $ids = $id ? [(int) $id] : DB::table('scene_attachments')->where('expires_at', '<=', now('UTC'))->whereIn('state', ['clean', 'deletion_pending'])->orderBy('id')->limit(100)->pluck('id')->all();
            $results = [];
            foreach ($ids as $target) {
                $results[] = $this->option('apply') ? $service->purge((int) $target, (string) $this->option('policy-version')) : $service->inspect((int) $target);
            }
            $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'read_only', 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (SceneWorkflowException $e) {
            $this->line(json_encode(['code' => $e->errorCode, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }
}
