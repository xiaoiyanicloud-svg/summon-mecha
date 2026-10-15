<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 下发任务 —— 技能下发的状态机(Go 服务驱动,v3.4 步骤 3 新增)。
 *
 * ⚠ 幂等由应用层保证(回收任务前先原子翻转 entitlement 状态);
 *   MySQL 5.7 无"部分唯一索引",故只建索引不建唯一键。
 */
class DeployTask extends Model
{
    protected $table = 'deploy_task';

    public const OP_INSTALL = 1;

    public const OP_UNINSTALL = 2;

    public const OP_ROLLBACK = 3;

    public const OP_ENABLE = 4;

    public const OP_DISABLE = 5;

    public const OP_RECLAIM = 6; // 试用到期回收

    /**
     * 全部合法操作码 —— **参数校验的唯一事实源**。
     *
     * ⚠ 为什么要有它:此前 `DeployController::store()` 把白名单写成字面量 `'in:1,2,3,4,5'`,
     *   而本类早已有 `OP_RECLAIM=6`(迁移注释也写「6回收」)。两条清单各写各的 →
     *   合法的「试用到期回收」下发被参数校验挡死,报的还是"参数非法",看不出是漏了一个码。
     *   以后新增操作只改这里,校验规则自动跟上。
     */
    public const OPERATIONS = [
        self::OP_INSTALL,
        self::OP_UNINSTALL,
        self::OP_ROLLBACK,
        self::OP_ENABLE,
        self::OP_DISABLE,
        self::OP_RECLAIM,
    ];

    /**
     * 下发侧的**错误码词汇表** —— 写进 `deploy_task.error_code` 的机器可读值。
     *
     * ⚠ 为什么必须有这张表(P11):此前只有签名失败一个码,离线 / 无包 / 未确权
     *   全部落成**泛化的 failed**,事后只能靠 `error_msg` 的中文文案去猜发生了什么
     *   (而文案是可以随手改的)。灰度成功率按状态统计,分不清"没装成"和"根本没装"。
     *
     * 这些码由两端共同产生:
     *   · 设备侧(Agent)自报 —— 经 `POST /api/agent/evt` 的 `error_code` 字段;
     *   · 服务端判定 —— 在 `DeployTaskService` 里直接落在任务上。
     * 服务端**不校验**设备自报的码(设备侧可能有本仓还不知道的失败类型),但本表是
     * 双方约定使用的那一批。取值即线上契约,改名可以,改值须报批。
     */
    public const ERR_SIG_INVALID = 'SIG_INVALID';           // 设备侧:签名校验未通过

    public const ERR_DOWNLOAD_FAILED = 'DOWNLOAD_FAILED';   // 设备侧:包下载失败

    /**
     * 无包 —— 两种情形,**语义都是"这次什么都没装"**:
     *   ① 设备侧:拿到空 `package_url`,做 dry install(只走状态机),如实上报;
     *   ② 平台侧:根本没给该版本分发交付物。
     *
     * ⚠ 带此码的 `success` 是**空跑**:状态机走通了,但设备上一个文件都没落。
     *   判定处一律用 {@see self::isDryRun()} / {@see self::scopeNotDryRun()},
     *   别在别处再写一遍字符串比较。
     */
    public const ERR_PKG_MISSING = 'PKG_MISSING';

    public const ERR_OFFLINE = 'OFFLINE';                   // 服务端:设备离线,任务挂起等上线

    public const ERR_NOT_BOUND = 'NOT_BOUND';               // 服务端:设备从未登记(无 binding)

    protected $fillable = [
        'task_no',
        'did',
        'channel',
        'skill_id',
        'skill_version_id',
        'entitlement_id',
        'operation',
        'status',
        'retry_count',
        'next_retry_at',
        'delivered_at',
        'package_url',
        'package_hash',
        'signature',
        'error_code',
        'error_msg',
        'created_by',
        'completed_at',
    ];

    protected $casts = [
        'operation'       => 'integer',
        'retry_count'     => 'integer',
        'next_retry_at'   => 'datetime',
        'delivered_at'    => 'datetime',
        'completed_at'    => 'datetime',
    ];

    /**
     * 这条任务是不是"空跑" —— 状态是 success,但**什么都没装**(无包可装)。
     *
     * 用途:落 `install_record` 前过滤(不能凭空造一条"装上了"),以及灰度成功率
     * 的口径(空跑既不算成功也不算失败)。
     */
    public function isDryRun(): bool
    {
        return $this->error_code === self::ERR_PKG_MISSING;
    }

    /**
     * 排除空跑的查询作用域 —— 灰度指标口径专用。
     *
     * `where('error_code','!=',X)` 在 SQL 里对 NULL **恒为假**,空码的任务会被一并滤掉,
     * 故必须带 orWhereNull;这段逻辑只应存在一处。
     */
    public function scopeNotDryRun($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('error_code')->orWhere('error_code', '!=', self::ERR_PKG_MISSING);
        });
    }
}
