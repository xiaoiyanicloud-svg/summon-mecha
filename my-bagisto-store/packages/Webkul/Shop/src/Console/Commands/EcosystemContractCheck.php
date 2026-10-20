<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Ecosystem\ContractCoverageChecker;

/**
 * Q-01 契约门禁：路由 / ACL / 错误码 / 状态 / OpenAPI 一致性。
 *
 *   php artisan ecosystem:contract-check
 *   php artisan ecosystem:contract-check --json=build/ecosystem-contract.json
 *
 * 有任一违规时返回 FAILURE（退出码 1），用于 CI / 发布前阻断。
 * 本命令只读配置、路由和源码，不查业务库、不写任何文件（除 --json 指定的输出）。
 */
class EcosystemContractCheck extends Command
{
    protected $signature = 'ecosystem:contract-check
        {--json= : 将完整结果写入指定路径（JSON，不含密钥）}
        {--summary : 只输出统计与违规条数}';

    protected $description = '校验生态域路由、ACL、错误码、状态词表与 OpenAPI 契约是否漂移';

    public function handle(ContractCoverageChecker $checker): int
    {
        $result = $checker->check();

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            $path = (string) $this->option('json');
            $directory = dirname($path);

            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $this->line('结果已写入：'.$path);
        }

        if ($this->option('summary')) {
            $this->line(sprintf(
                '契约 %s：后台路由 %d；公开路由 %d；契约登记 %d；错误码 %d；状态快照 %d；违规 %d。',
                $result['version'],
                $result['stats']['admin_routes'],
                $result['stats']['public_routes'],
                $result['stats']['contracted_routes'],
                $result['stats']['error_codes'],
                $result['stats']['state_inventories'],
                count($result['violations']),
            ));
        } else {
            $this->line('契约版本：'.$result['version']);
            $this->table(
                ['项目', '数量'],
                collect($result['stats'])->map(fn ($value, $key) => [$key, $value])->values()->all(),
            );
        }

        if ($result['ok']) {
            $this->info('契约检查通过。');

            return self::SUCCESS;
        }

        $this->error(sprintf('契约检查失败：%d 项违规。', count($result['violations'])));

        foreach ($result['violations'] as $violation) {
            $this->line(sprintf(' - [%s] %s：%s', $violation['code'], $violation['subject'], $violation['detail']));
        }

        return self::FAILURE;
    }
}
