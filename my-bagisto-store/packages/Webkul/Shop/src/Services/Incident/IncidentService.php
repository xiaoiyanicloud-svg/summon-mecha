<?php

namespace Webkul\Shop\Services\Incident;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Shop\Models\Incident;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\Ops\AdminAlertService;

/**
 * 事故上报与追溯（v3.4 计划 §4.19）。
 *
 * **本服务的核心是 `traceFor()`：在报案那一刻把证据链固化下来。**
 *
 * 为什么必须固化而不是"事后现查"：
 *   - 技能版本会归档 → 事后查"当时跑的是哪个版本"可能已经查不到
 *   - 交付物会被覆盖上传 → 事后拿到的 sha256 未必是当时那一个
 *   - 设备会解绑、转让 → 事后"这台设备当时属于谁"会变
 *   - 安装记录会被更新（升级/卸载）→ 事后看到的是最终态，不是事故时的状态
 * 出事故时事后回头查，看到的全是**现在的样子**，而不是**出事时的样子** ——
 * 那样的追溯在定责时是站不住的。
 *
 * 第二条原则：**查不到的环节要显式记下来**，不能静默省略。
 * "没有安装记录"与"我们没去查安装记录"是两件完全不同的事，
 * 而 `trace.missing` 让调查者能区分这两者。
 *
 * 第三条：**严重/重大事故在报案那一刻就通知运营**，不等小时级的轮询命令。
 * 轮询是兜底，不是替代 —— 见 `alertIfEscalated()`。
 */
class IncidentService
{
    public function __construct(protected AdminAlertService $alerts) {}

    /**
     * 上报一起事故。
     *
     * @param  array{skill_id?:int|null, device_id?:int|null, severity:string, occurred_at?:string|null,
     *               description:string, evidence?:array|null}  $data
     */
    public function report(int $customerId, array $data): Incident
    {
        $productId = isset($data['skill_id']) ? (int) $data['skill_id'] : null;
        $deviceId = isset($data['device_id']) ? (int) $data['device_id'] : null;

        $trace = $this->traceFor($customerId, $productId, $deviceId);

        // A2 · 单号是秒级时间戳,同一客户同一秒连报两次会撞 `uk_incident_no` → 500。
        //   号里已加随机后缀(suffix 让碰撞概率可忽略),这里再兜一层:
        //   万一真撞了,唯一索引会抛 —— 捕获后换一个号重来,而不是把 500 甩给报案人。
        //   (报案是主操作;不能因为内部编号撞车而让"刚出过事的人"再报一遍。)
        $attempts = 0;

        while (true) {
            try {
                $incident = Incident::create([
                    'incident_no'       => $this->generateNo($customerId),
                    'skill_id'          => $productId,
                    'skill_version_id'  => $trace['resolved']['skill_version_id'] ?? null,
                    'device_binding_id' => $trace['resolved']['device_binding_id'] ?? null,
                    'customer_id'       => $customerId,
                    'severity'          => $data['severity'],
                    'occurred_at'       => $data['occurred_at'] ?? now(),
                    'description'       => $data['description'],
                    'evidence'          => $data['evidence'] ?? null,
                    // ⚠ 默认"尚未认定"——不预设责任方。报案时通常还不知道是谁的责任，
                    //   为了填满字段而猜一个，等于往追溯里掺假。
                    'responsibility'    => Incident::RESPONSIBILITY_UNKNOWN,
                    'trace'             => $trace,
                    'status'            => Incident::STATUS_REPORTED,
                ]);

                break;
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e) || ++$attempts >= 5) {
                    throw $e;
                }
            }
        }

        $this->alertIfEscalated($incident);

        return $incident;
    }

    /**
     * 唯一键冲突(MySQL 1062 / SQLite 唯一约束)。
     */
    protected function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * 严重/重大事故 —— 报案那一刻就通知运营。
     *
     * 为什么不能只靠 `ops:alert-open-incidents`（小时级轮询）：
     *   那个命令要等 SLA 到点（严重 4 小时）才提醒，而且最长再等一个轮询周期。
     *   而严重事故的性质是"这个技能刚刚把人撞了，**可能还有第二个人正在用**" ——
     *   近 5 个小时的沉默，对一个会物理伤人的功能来说太长。
     *   轮询是**兜底**（防止有人漏看即时告警），不是替代。
     *
     * ⚠ **报案是主操作，告警是副操作**：告警发不出去**不能**让报案失败 ——
     *   报案人已经出过一次事了，不能因为平台内部通知写不进去而让他再报一遍。
     *   所以这里吞掉异常，但按本项目既定做法**留下 error 日志**（同 `OperationLogger`）。
     *
     * ⚠ 去重键用 `incident:{id}`，与轮询命令的 `incident:open:{id}` **刻意不同**：
     *   两者是两件事（"刚发生" / "过了时限还没人处理"），不该互相压制。
     */
    protected function alertIfEscalated(Incident $incident): void
    {
        if (! $incident->requiresEscalation()) {
            return;
        }

        try {
            $this->alerts->alert(
                'incident_new',
                sprintf('【%s事故】%s 刚被上报', $incident->severityLabel(), $incident->incident_no),
                sprintf(
                    "事故编号：%s\n严重程度：%s\n发生时间：%s\n上报时间：%s\n处理状态：%s\n\n"
                    ."情况描述：\n%s\n\n"
                    ."证据链已在报案时冻结，可在后台查看；请尽快受理。\n%s",
                    $incident->incident_no,
                    $incident->severityLabel(),
                    $incident->occurred_at?->toDateTimeString() ?? '（未填）',
                    $incident->created_at?->toDateTimeString(),
                    $incident->statusLabel(),
                    mb_substr((string) $incident->description, 0, 300),
                    route('admin.customers.incidents.show', $incident->id),
                ),
                'incident:'.$incident->id,
                [
                    'incident_id' => $incident->id,
                    'incident_no' => $incident->incident_no,
                    'severity'    => $incident->severity,
                ],
                $incident->severity === Incident::SEVERITY_CRITICAL
                    ? AdminAlertService::LEVEL_URGENT
                    : AdminAlertService::LEVEL_WARN,
            );
        } catch (Throwable $e) {
            Log::error('严重事故即时告警发送失败（事故本身已入库，未丢失）：'.$e->getMessage(), [
                'incident_id' => $incident->id,
                'incident_no' => $incident->incident_no,
                'severity'    => $incident->severity,
            ]);
        }
    }

    /**
     * 推进处理状态 / 定责。
     *
     * ⚠ **定责必须有说明**：把责任判给某一方是会影响真金白银与法律关系的判断，
     *   没有书面依据的"已定责"等于没定。
     *
     * ⚠ 处理日志写 `incident_handling_log`(append-only)，**不写 `evidence`** —— 后者是
     *   报案人提交的证据，两者的可见范围不同（见迁移 `2026_10_14_000005` 的说明）。
     *
     * A5:此前处置记录写 `incident.handling_log` 一列,每次「读出整列 → 追加 → 整列写回」——
     *   两名管理员并发处置时后写覆盖先写,一条记录消失。现在每次处置**插一行**,物理上不可覆盖;
     *   状态推进本身也用行锁串行化(否则 status/resolved_at 一样会被并发覆盖)。
     */
    public function advance(
        Incident $incident,
        string $status,
        ?string $responsibility = null,
        ?string $note = null,
    ): void {
        $payload = ['status' => $status];

        if ($responsibility !== null) {
            $payload['responsibility'] = $responsibility;
        }

        if (in_array($status, [Incident::STATUS_RESOLVED, Incident::STATUS_CLOSED], true)) {
            $payload['resolved_at'] = now();
        }

        DB::transaction(function () use ($incident, $payload, $status, $responsibility, $note) {
            // 行锁:把对同一事故的并发处置串起来,避免后写覆盖先写
            $locked = Incident::query()->whereKey($incident->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $locked->update($payload);

            // append-only:每次处置插一行,不整列重写
            DB::table('incident_handling_log')->insert([
                'incident_id'    => $locked->id,
                'admin_id'       => auth()->guard('admin')->id(),
                'status'         => $status,
                'responsibility' => $responsibility,
                'note'           => $note,
                'created_at'     => now(),
            ]);
        });
    }

    /**
     * 证据链快照。
     *
     * @return array{
     *     captured_at: string,
     *     resolved: array{skill_version_id?:int, device_binding_id?:int},
     *     entitlement: array|null,
     *     skill_version: array|null,
     *     deliverable: array|null,
     *     device: array|null,
     *     install: array|null,
     *     missing: string[]
     * }
     */
    public function traceFor(int $customerId, ?int $productId, ?int $deviceId): array
    {
        $missing = [];
        $resolved = [];

        // ---- 1) 授权：这个人买过这个技能吗（买过哪一版）
        $entitlement = null;
        $entitlementId = 0;
        $versionId = 0;

        if ($productId) {
            $row = DB::table('skill_entitlements')
                ->where('customer_id', $customerId)
                ->where('product_id', $productId)
                ->orderByDesc('id')
                ->first();

            if ($row) {
                $entitlementId = (int) $row->id;
                $versionId = (int) $row->skill_version_id;

                $entitlement = [
                    'id'               => $row->id,
                    'order_id'         => $row->order_id,
                    'order_item_id'    => $row->order_item_id,
                    'skill_version_id' => $row->skill_version_id,
                    'license_key'      => $row->license_key,
                    'status'           => $row->status,
                    'delivery_channel' => $row->delivery_channel,
                    'granted_at'       => $row->granted_at,
                    'expires_at'       => $row->expires_at,
                    'max_activations'  => $row->max_activations,
                ];
            } else {
                $missing[] = '该用户在本平台没有此技能的授权记录（可能是他处购买、或授权记录已被删除）';
            }
        } else {
            $missing[] = '报案时未指明技能，无法关联授权';
        }

        // ---- 2) 技能版本：当时跑的是哪一版
        if (! $versionId && $productId) {
            // 没有授权记录时退而求其次：取当前 published 版本，并**在 missing 里标明这是推断值**
            $versionId = (int) DB::table('skill_versions')
                ->where('product_id', $productId)
                ->where('status', 'published')
                ->orderByDesc('id')
                ->value('id');

            if ($versionId) {
                $missing[] = '无授权记录可依据，技能版本取的是线索（当前 published 版本），不一定是事故时的版本';
            }
        }

        $skillVersion = null;

        if ($versionId) {
            $v = DB::table('skill_versions')->where('id', $versionId)->first();

            if ($v) {
                $resolved['skill_version_id'] = (int) $v->id;

                $skillVersion = [
                    'id'              => $v->id,
                    'product_id'      => $v->product_id,
                    'version'         => $v->version,
                    'status'          => $v->status,
                    'channel'         => $v->channel,
                    'risk_level'      => $v->risk_level,
                    'seller_id'       => $v->seller_id,
                    'released_at'     => $v->released_at,
                    'manifest_schema' => $v->manifest_schema,
                ];
            }
        } else {
            $missing[] = '无法确定技能版本';
        }

        // ---- 3) 交付物：当时装的是哪个包。sha256 是定责时最硬的一条证据。
        $deliverable = null;

        if ($versionId) {
            $a = DB::table('skill_assets')
                ->where('skill_version_id', $versionId)
                ->where('carrier_type', SkillAsset::CARRIER_FILE)
                ->orderByDesc('id')
                ->first();

            if ($a) {
                $deliverable = [
                    'asset_id'   => $a->id,
                    'file_name'  => $a->file_name,
                    'file_hash'  => $a->file_hash,
                    'file_size'  => $a->file_size,
                    'entrypoint' => $a->entrypoint,
                ];
            } else {
                $missing[] = '该版本的交付物记录不存在（技能包可能已被替换或删除）';
            }
        }

        // ---- 4) 设备：出事的是哪一台、归谁
        $device = null;
        $did = null;

        if ($deviceId) {
            $d = DB::table('devices')->where('id', $deviceId)->first();

            if ($d) {
                // devices.binding_id 是直连，比反向 where(devices_id) 更准；
                // 历史数据可能只有反向那一条，所以两路都试。
                $binding = $d->binding_id
                    ? DB::table('device_binding')->where('id', $d->binding_id)->first()
                    : DB::table('device_binding')->where('devices_id', $d->id)->first();

                if ($binding) {
                    $resolved['device_binding_id'] = (int) $binding->id;
                    $did = $binding->did;
                }

                // 型号快照 —— 机型库以后可能改通道声明，但"当时是什么型号"不会变
                $model = $d->model_code
                    ? DB::table('device_model')->where('model_code', $d->model_code)->first()
                    : null;

                $device = [
                    'device_id'  => $d->id,
                    'name'       => $d->display_name,
                    'sn'         => $d->sn,
                    'model_code' => $d->model_code,
                    'model_name' => $model->model_name ?? null,
                    'compute'    => $model->compute_platform ?? null,
                    // ⚠ 优先用**设备自报的固件**：机型库那份是"厂商说有哪些版本"，
                    //   出事那台实际跑的是哪个版本，只有设备自己知道。
                    'firmware'   => $d->firmware_version,
                    'firmware_known_versions' => $model->firmware_versions ?? null,
                    'binding'    => $binding ? [
                        'id'                => $binding->id,
                        'did'               => $binding->did,
                        'sn'                => $binding->sn,
                        'source'            => $binding->source,
                        'bind_status'       => $binding->bind_status,
                        // ⚠ 原样冻结，**不要**改成 "读时的在线判断"。
                        //   快照的契约是"记下当时库里写的是什么"，不是"现在是什么"。
                        //   `online_status` 这一列本身是历史标记（只写过 true），
                        //   所以它这里表达的是"当时这台设备曾经连上过"——
                        //   真正的"事故时是否在线"要看同一份快照里的 `last_online_at`。
                        //   两者并排放着，调查者自己判断；平台不替历史下结论。
                        'online_status'     => $binding->online_status,
                        'last_online_at'    => $binding->last_online_at,
                        'owner_customer_id' => $binding->owner_customer_id,
                    ] : null,
                ];

                if (! $binding) {
                    $missing[] = '该设备没有设备绑定记录（真相源为空）—— 归属无法从绑定侧确认';
                } elseif ((int) $binding->owner_customer_id !== $customerId) {
                    // 归属与报案人不一致值得调查 —— 也可能是家人/同事代为操作，不预设结论
                    $missing[] = '报案人不是该设备绑定的归属人 —— 请核实关系';
                }

                if (! $d->firmware_version) {
                    $missing[] = '设备未上报固件版本 —— 无法判断是否为已知有问题的固件';
                }
            } else {
                $missing[] = '报案所指的设备在平台不存在';
            }
        } else {
            $missing[] = '报案时未指明设备';
        }

        // ---- 5) 安装记录：这个包是怎么装上去的
        $install = null;

        if ($entitlementId || $did) {
            $q = DB::table('install_record');

            if ($entitlementId) {
                $q->where('entitlement_id', $entitlementId);
            } else {
                $q->where('did', $did);
            }

            $i = $q->orderByDesc('id')->first();

            if ($i) {
                $install = [
                    'id'               => $i->id,
                    'did'              => $i->did,
                    'skill_version_id' => $i->skill_version_id,
                    'status'           => $i->status,
                    'current_version'  => $i->current_version,
                    'installed_at'     => $i->installed_at,
                    'removed_at'       => $i->removed_at,
                    'deploy_task_id'   => $i->deploy_task_id,
                ];
            } else {
                $missing[] = '没有安装记录 —— 可能是人工通道自行安装（平台不掌握安装事实）';
            }
        }

        return [
            'captured_at'   => now()->toDateTimeString(),
            'resolved'      => $resolved,
            'entitlement'   => $entitlement,
            'skill_version' => $skillVersion,
            'deliverable'   => $deliverable,
            'device'        => $device,
            'install'       => $install,
            // 查不到的环节显式列出 —— 别让"没查到"看起来像"没问题"
            'missing'       => $missing,
        ];
    }

    /**
     * 生成事故编号。
     *
     * A2:此前是 `while (Incident::where('incident_no', $no)->exists())` ——
     * **check-then-act,非原子**:两个并发请求可能都查到"不存在"、都取同一个号,
     * 后插入的那个撞 `uk_incident_no` → 500。现在改成"时间戳 + 客户 + 随机后缀",
     * 唯一的兜底交给数据库唯一索引(见 `report()` 的 catch-retry)。
     *
     * ⚠ 用随机而不是自增后缀:自增后缀在并发下反而**更容易**撞(两个请求都从 -1 开始试),
     *   而且不必要地多一次"查一下有没有"的往返 —— 那正是原来的病根。
     */
    protected function generateNo(int $customerId): string
    {
        // `I` + ymdHis(12) + `-` + 客户号 + `-` + 6 位随机 ≤ 32,列长 40 够放
        return 'I'.now()->format('ymdHis').'-'.$customerId.'-'.Str::upper(Str::random(6));
    }
}
