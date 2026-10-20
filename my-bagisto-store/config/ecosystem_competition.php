<?php

/*
|--------------------------------------------------------------------------
| G3 人工赛务开关与参数
|--------------------------------------------------------------------------
|
| 所有写能力默认关闭。G3-01 规则未签署、G3-12 整届演练未完成前，
| 生产环境不得打开 write_enabled；打开也仍然要受：
|   · 活动已发布（G1）；
|   · 报名 scope 已激活（G2 双闸）；
|   · 赛项 rules_profile 完整且已确认；
|   · 赛项 status=active。
|
| 公开赛程是独立开关：关闭时公开接口 404 且不查库。
|
*/
return [
    // 赛务写能力总闸（建队/邀请/报名归属/参赛确认/作品/赛程导入）。
    'write_enabled' => (bool) env('ECOSYSTEM_COMPETITION_WRITE_ENABLED', false),

    // 公开赛程开关。默认关闭；发布阶段不等于对公众可见。
    'public_schedule_enabled' => (bool) env('ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED', false),

    // G3-08 公开成绩开关。关闭时公开接口 404 且不查库。
    'public_results_enabled' => (bool) env('ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED', false),

    // G3-11 公开展示开关。与公开成绩独立；未授权/未发布的作品永不出现。
    'public_showcase_enabled' => (bool) env('ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED', false),

    // 规则契约版本。规则结构变化时必须递增，并同步 G3-01 文档。
    'rule_profile_version' => 'g3-02-v1',

    /*
    | 状态词表。放在配置里是为了让模型守卫、服务和测试共用同一事实源。
    */
    'statuses' => [
        'series'      => ['draft', 'active', 'archived'],
        'edition'     => ['draft', 'published', 'archived'],
        'track'       => ['draft', 'active', 'closed'],
        'team'        => ['active', 'disbanded'],
        'member'      => ['invited', 'active', 'declined', 'left', 'removed'],
        'entry'       => ['draft', 'submitted', 'confirmed', 'withdrawn', 'disqualified'],
        'submission'  => ['draft', 'submitted', 'correction_required', 'locked', 'withdrawn'],
        'stage'       => ['draft', 'published'],
        'match'       => ['draft', 'published', 'canceled'],
        'invite'      => ['pending', 'accepted', 'declined', 'revoked', 'expired'],
        'result'      => ['draft', 'submitted', 'provisional', 'final', 'revising'],
        'result_version' => ['draft', 'submitted', 'provisional', 'final', 'superseded'],
        'result_scope'   => ['track', 'match'],
        'forfeit'     => ['none', 'absent', 'forfeit', 'disqualified'],
        'appeal'      => ['submitted', 'accepted', 'investigating', 'decided', 'rejected', 'withdrawn'],
        'appeal_event'=> ['submitted', 'accepted', 'investigating', 'decision', 'withdrawn'],
        'appeal_decision' => ['upheld', 'partially_upheld', 'rejected', 'inadmissible'],
        'award'       => ['draft', 'pending_review', 'published', 'revoked'],
        'award_version' => ['draft', 'published', 'pending_review', 'superseded', 'revoked'],
        'showcase'    => ['draft', 'submitted', 'approved', 'published', 'rejected', 'withdrawn'],
        'showcase_type' => ['award', 'recap'],
    ],

    // 每个赛项的人数硬上限；实际上下限来自 rules_profile.eligibility。
    'hard_limits' => [
        'team_members' => 32,
        'invites_per_team' => 32,
        'tracks_per_edition' => 50,
    ],

    // 邀请码：只存 sha256，默认 7 天有效、一次性。
    'invite' => [
        'token_bytes' => 24,
        'ttl_seconds' => (int) env('ECOSYSTEM_COMPETITION_INVITE_TTL', 604800),
    ],

    // 作品附件：独立私有盘，不走 ecosystem_media 的公开审核语义。
    'attachment' => [
        'disk' => env('ECOSYSTEM_COMPETITION_ATTACHMENT_DISK', 'local'),
        'directory' => 'ecosystem/competition-submissions',
        'max_bytes' => (int) env('ECOSYSTEM_COMPETITION_ATTACHMENT_MAX_BYTES', 20971520),
        'max_per_version' => (int) env('ECOSYSTEM_COMPETITION_ATTACHMENT_MAX_PER_VERSION', 10),
        // 真实 MIME => 允许扩展名。服务端只信 finfo，不信客户端 Content-Type。
        'mimes' => [
            'application/pdf'  => ['pdf'],
            'image/jpeg'       => ['jpg', 'jpeg'],
            'image/png'        => ['png'],
            'image/webp'       => ['webp'],
            'application/zip'  => ['zip'],
            'text/plain'       => ['txt', 'md', 'csv', 'json', 'log'],
            'video/mp4'        => ['mp4'],
        ],
    ],

    // CSV 赛程导入限制。
    'schedule_import' => [
        'max_rows' => (int) env('ECOSYSTEM_COMPETITION_IMPORT_MAX_ROWS', 2000),
        'max_bytes' => (int) env('ECOSYSTEM_COMPETITION_IMPORT_MAX_BYTES', 2097152),
        'columns' => [
            'stage_key',
            'stage_name',
            'stage_order',
            'match_key',
            'round_no',
            'starts_at',
            'ends_at',
            'venue',
            'home_entry_no',
            'away_entry_no',
        ],
    ],

    /*
    | 规则契约必填键。值只用于错误信息定位，不提供默认业务值。
    | 缺失任意一项，赛项不能激活。
    */
    'required_rule_keys' => [
        'owners.rules',
        'owners.scoring',
        'owners.appeal',
        'owners.safety',
        'eligibility.min_members',
        'eligibility.max_members',
        'eligibility.robot_declaration_required',
        'scoring.policy',
        'tie.policy',
        'appeal.window_minutes',
        'appeal.allowed_roles',
        'appeal.evidence_types',
        'safety.owner_role',
        'safety.plan_ref',
        'safety.emergency_contact_ref',
    ],

    // 本批只允许人工评分/人工同分；自动策略属于 G3-07。
    // 策略注册表是唯一白名单：新增策略必须实现 CompetitionScoringPolicy 并在此登记。
    // 不允许表达式、脚本或用户公式。
    'scoring_policies' => [
        'manual'     => \Webkul\Shop\Services\Ecosystem\Scoring\ManualScoringPolicy::class,
        'total_desc' => \Webkul\Shop\Services\Ecosystem\Scoring\TotalDescScoringPolicy::class,
    ],
    'allowed_scoring_policies' => ['manual', 'total_desc'],
    'allowed_tie_policies' => ['manual', 'shared', 'strict'],

    // G3-07 精度边界。
    'scoring' => [
        'max_fields' => 8,
        'max_scale' => 6,
        // 定点整数允许的最大绝对位数；超过拒绝，避免溢出后“排名看起来正常”。
        'max_digits' => 18,
        'missing_policies' => ['exclude', 'zero', 'disqualify'],
        'forfeit_policies' => ['exclude', 'zero', 'disqualify'],
    ],

    // 本批只允许队长申诉；申诉动作在 G3-09 实现。
    'allowed_appeal_roles' => ['captain'],

    // G3-09 申诉证据与窗口。
    'appeal' => [
        'disk' => env('ECOSYSTEM_COMPETITION_APPEAL_DISK', 'local'),
        'directory' => 'ecosystem/competition-appeals',
        'max_bytes' => (int) env('ECOSYSTEM_COMPETITION_APPEAL_MAX_BYTES', 10485760),
        'max_files' => (int) env('ECOSYSTEM_COMPETITION_APPEAL_MAX_FILES', 6),
        // 规则里的证据类型 => 允许的真实 MIME。
        'evidence_mimes' => [
            'pdf'   => ['application/pdf'],
            'image' => ['image/jpeg', 'image/png', 'image/webp'],
            'video' => ['video/mp4'],
            'text'  => ['text/plain'],
        ],
    ],

    // G3-11 展示授权与技能提审交接。
    'showcase' => [
        'max_awards_per_track' => 20,
        'public_fields' => ['title', 'summary', 'team_name', 'track_name', 'award_name'],
    ],
];
