<?php

/**
 * 客服快捷回复知识库（配置注册表）。
 *
 * 为什么放配置而不是数据库：
 *   - 话术是运营资产，改动频率低于工单，但需要**可评审、可回滚**；
 *   - 版本跟着代码走，发布/回滚不会出现「代码退了、库里的话术还在」；
 *   - 与项目既有的 deploy_gateways / payment_gateways 等注册表保持同一姿势。
 *
 * 结构约定：
 *   groups.<key>.label  分组名（按工单 category 分组，另有 common 通用组）
 *   groups.<key>.items[] 每条 { key, label, content }
 *     - key     全局唯一，统计口径就是它；**改名等于丢历史数据**，不要改
 *     - label   工作台胶囊上显示的短标签
 *     - content 实际填入回复框的文字
 *
 * 使用统计见 support_quick_reply_event（insert=点击插入，send=实际发出）。
 */
return [
    'groups' => [
        'common' => [
            'label' => '通用',
            'items' => [
                [
                    'key'     => 'common.received',
                    'label'   => '已收到',
                    'content' => '已收到，我们正在为你排查。',
                ],
                [
                    'key'     => 'common.more_info',
                    'label'   => '补充信息',
                    'content' => '还需要补充一下设备型号、系统版本或报错截图，方便我们进一步定位。',
                ],
                [
                    'key'     => 'common.wait',
                    'label'   => '稍等片刻',
                    'content' => '正在核实，请稍等片刻。',
                ],
                [
                    'key'     => 'common.done',
                    'label'   => '已处理',
                    'content' => '问题已处理完成，请你在设备上再验证一次。如仍未解决，可直接在此会话继续回复。',
                ],
            ],
        ],

        'delivery' => [
            'label' => '下发/安装',
            'items' => [
                [
                    'key'     => 'delivery.steps',
                    'label'   => '安装步骤',
                    'content' => '请在设备端打开「设置 - 技能管理」，按提示完成安装；如果页面没有刷新，请退出后重新进入技能列表。',
                ],
                [
                    'key'     => 'delivery.timeout',
                    'label'   => '连接超时',
                    'content' => '连接超时通常是设备网络波动导致的。请确认设备已联网后重试一次，并把报错截图发给我们。',
                ],
                [
                    'key'     => 'delivery.remote',
                    'label'   => '远程协助',
                    'content' => '可以安排远程协助。请把设备保持在开机联网状态，方便的时间段告诉我们。',
                ],
                [
                    'key'     => 'delivery.manual',
                    'label'   => '人工安装包',
                    'content' => '这台设备当前走人工安装通道。我们会把安装包和步骤发给你，按步骤操作即可。',
                ],
            ],
        ],

        'skill' => [
            'label' => '技能功能',
            'items' => [
                [
                    'key'     => 'skill.start_failed',
                    'label'   => '启动失败',
                    'content' => '技能启动失败时，请先确认设备电量与网络正常，然后重新进入技能页再启动一次；仍失败请把报错内容发给我们。',
                ],
                [
                    'key'     => 'skill.mismatch',
                    'label'   => '与描述不符',
                    'content' => '很抱歉给你带来困扰。请说明实际表现与页面描述的差异，我们会核实并给出处理方案。',
                ],
                [
                    'key'     => 'skill.reinstall',
                    'label'   => '卸载重装',
                    'content' => '可以在设备端先卸载该技能，再重新安装一次。重装前请确认设备网络稳定。',
                ],
                [
                    'key'     => 'skill.version',
                    'label'   => '版本信息',
                    'content' => '方便的话请提供技能名称、当前版本号和设备型号，我们据此定位兼容性问题。',
                ],
            ],
        ],

        'device' => [
            'label' => '设备问题',
            'items' => [
                [
                    'key'     => 'device.offline',
                    'label'   => '设备无法连接',
                    'content' => '请先确认设备已开机并连接网络，然后在平台里重新绑定一次。仍然失败请提供设备序列号。',
                ],
                [
                    'key'     => 'device.flapping',
                    'label'   => '频繁掉线',
                    'content' => '频繁掉线可能与网络环境有关。请尽量使用稳定的 WiFi，并告知我们大致的掉线频率。',
                ],
                [
                    'key'     => 'device.repair',
                    'label'   => '需要检修',
                    'content' => '如果确认是硬件问题，我们会安排检修流程。请提供购买订单号与设备序列号。',
                ],
                [
                    'key'     => 'device.serial',
                    'label'   => '要序列号',
                    'content' => '请提供设备底部的序列号（或设备页显示的设备编号），方便我们核对设备归属。',
                ],
            ],
        ],

        'payment' => [
            'label' => '支付/退款',
            'items' => [
                [
                    'key'     => 'payment.refund_progress',
                    'label'   => '退款进度',
                    'content' => '退款会按原支付渠道退回，到账时间以支付平台为准。请提供订单号，我们帮你查当前进度。',
                ],
                [
                    'key'     => 'payment.cancel',
                    'label'   => '取消订单',
                    'content' => '请提供订单号，我们核实订单状态后为你处理取消。已激活的虚拟商品按售后规则处理。',
                ],
                [
                    'key'     => 'payment.invoice',
                    'label'   => '开发票',
                    'content' => '可以开票。请在「我的 - 发票」里提交抬头与税号，或把开票信息发给我们。',
                ],
                [
                    'key'     => 'payment.rule',
                    'label'   => '售后规则',
                    'content' => '虚拟商品售后规则：试用不支持退款；未下发可退；已激活可申诉，我们会按实际情况处理。',
                ],
            ],
        ],

        'other' => [
            'label' => '其他',
            'items' => [
                [
                    'key'     => 'other.order_no',
                    'label'   => '要订单号',
                    'content' => '请把订单号发给我们，方便快速定位你的问题。',
                ],
                [
                    'key'     => 'other.follow_up',
                    'label'   => '继续跟进',
                    'content' => '这个问题我们会继续跟进，有进展会第一时间在这里回复你。',
                ],
            ],
        ],
    ],
];
