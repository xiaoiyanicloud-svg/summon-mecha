<?php

namespace Webkul\Shop\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;

/**
 * 赛事演示样例种子。
 *
 * 这里的 8 场比赛来自 EcoCenter 旧 mock,只用于本地/测试环境演示,**不是运营真实数据**。
 * production 等其它环境直接返回,不插入任何记录;不要把它当作正式发布渠道。
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (Event::count() > 0) {
            return;
        }

        $events = [
            ['name' => '机甲格斗大赛', 'type' => '格斗竞技', 'status' => 'registering', 'date' => '2026年8月15日 9:00-18:00', 'city' => '深圳', 'location' => '深圳国际会展中心', 'organizer' => '机甲格斗联盟', 'hot' => 98, 'teams' => 32, 'icon' => '🏟️', 'bg_color' => '#FEF3C7', 'detail' => '2026机甲格斗大赛深圳站即将开赛！汇聚全国顶尖选手，采用3V3团队赛制，奖金池50万元。现场设有观众体验区，可亲自操控迷你格斗机器人。', 'prize' => "🏆 冠军：50万元 + 奖杯\n🥈 亚军：20万元\n🥉 季军：10万元\n🌟 最佳设计奖：5万元", 'schedule' => "📌 8月15日 9:00 开幕式\n📌 10:00 小组赛\n📌 14:00 半决赛\n📌 17:00 决赛+颁奖", 'history' => '2025年首届大赛吸引60支队伍参赛，现场观众超5000人，线上直播观看量突破100万。', 'sort' => 98],
            ['name' => '机器人竞速赛', 'type' => '竞速赛', 'status' => 'upcoming', 'date' => '2026年9月1日 8:00-17:00', 'city' => '北京', 'location' => '北京亦庄机器人产业园', 'organizer' => '中国机器人运动协会', 'hot' => 85, 'teams' => 24, 'icon' => '🏁', 'bg_color' => '#E8F0FE', 'detail' => '2026机器人竞速赛北京站正式开启！包含地面竞速和空中竞速两个组别，赛道总长500米，设有多种障碍关卡。', 'prize' => "🏆 冠军：10万元 + 奖牌\n🥈 亚军：5万元\n🥉 季军：3万元", 'schedule' => "📌 9月1日 8:00 选手签到\n📌 9:00 预赛\n📌 13:00 半决赛\n📌 16:00 决赛", 'history' => '往届赛事吸引了来自全国50余所高校和企业队伍参与，打破了多项纪录。', 'sort' => 85],
            ['name' => '技术交流沙龙', 'type' => '行业沙龙', 'status' => 'ongoing', 'date' => '2026年8月22日 14:00-18:00', 'city' => '上海', 'location' => '上海陆家嘴金融中心', 'organizer' => '机器人产业联盟', 'hot' => 72, 'teams' => 0, 'icon' => '💬', 'bg_color' => '#F0F4FA', 'detail' => '聚焦「人形机器人商业化路径」，邀请行业专家、企业高管分享最新成果与市场洞察，现场圆桌讨论+自由交流。', 'prize' => '🎁 参与即送行业报告', 'schedule' => "📌 14:00 主题演讲\n📌 15:30 圆桌论坛\n📌 17:00 自由交流", 'history' => '已成功举办10届，累计参与人数超过2000人。', 'sort' => 72],
            ['name' => '高校机器人挑战', 'type' => '高校竞赛', 'status' => 'registering', 'date' => '2026年8月30日 线上赛', 'city' => '线上', 'location' => '线上平台', 'organizer' => '教育部机器人竞赛组委会', 'hot' => 90, 'teams' => 48, 'icon' => '🎯', 'bg_color' => '#E8F0FE', 'detail' => '面向全国高校在校生，参赛队伍需完成自主导航、物体抓取、语音交互三项任务。优秀队伍将获实习推荐和科研资助。', 'prize' => "🏆 一等奖：科研基金 5万元\n🥈 二等奖：3万元\n🥉 三等奖：1万元", 'schedule' => "📌 10:00 任务发布\n📌 14:00 提交截止\n📌 次日 公布结果", 'history' => '去年有超过200支队伍报名参赛，覆盖全国150所高校。', 'sort' => 90],
            ['name' => '改装工坊开放日', 'type' => '体验活动', 'status' => 'upcoming', 'date' => '2026年9月5日 10:00-17:00', 'city' => '广州', 'location' => '广州机器人创客空间', 'organizer' => '机器人创客空间', 'hot' => 65, 'teams' => 0, 'icon' => '🔧', 'bg_color' => '#FEF3C7', 'detail' => '向公众免费开放！现场提供改装工具与材料，专业工程师手把手指导改装技巧。适合爱好者、极客、亲子家庭。', 'prize' => '🎁 完成作品可带走纪念品', 'schedule' => "📌 10:00 开场介绍\n📌 10:30 改装教学\n📌 14:00 自由改装\n📌 16:30 作品展示", 'history' => '往期活动参与人数超过300人，满意度达98%。', 'sort' => 65],
            ['name' => '机器人格斗联赛', 'type' => '巡回联赛', 'status' => 'ongoing', 'date' => '2026年8月-12月（全国巡回）', 'city' => '全国巡回', 'location' => '全国10个城市', 'organizer' => '全国机器人格斗联赛组委会', 'hot' => 95, 'teams' => 120, 'icon' => '🏆', 'bg_color' => '#E8F0FE', 'detail' => '2026全国机器人格斗联赛覆盖深圳、北京、上海、广州、成都等10城。赛季总冠军获「机甲之王」称号及100万元奖金。', 'prize' => "🏆 总冠军：100万元 + 定制奖杯\n🥈 分站冠军：10万元\n🥉 最佳人气奖：5万元", 'schedule' => "📌 8月 深圳站\n📌 9月 北京站\n📌 10月 上海站\n📌 11月 广州站\n📌 12月 成都站（决赛）", 'history' => '联赛已成功举办三届，累计参赛队伍超过500支，已成为国内规模最大的机器人格斗赛事。', 'sort' => 95],
            ['name' => '机甲科技展', 'type' => '展览', 'status' => 'upcoming', 'date' => '2026年9月18日 9:00-17:00', 'city' => '杭州', 'location' => '杭州国际博览中心', 'organizer' => '机甲科技展组委会', 'hot' => 78, 'teams' => 0, 'icon' => '🎪', 'bg_color' => '#FCE4EC', 'detail' => '大型机甲主题科技展，现场展示多款人形与四足机器人，设互动体验区与新品首发。', 'prize' => '🎫 现场扫码领门票', 'schedule' => "📌 9:00 开幕\n📌 10:30 新品发布\n📌 14:00 互动体验", 'history' => '首届机甲科技展，预计观众超万人。', 'sort' => 78],
            ['name' => '机器人马拉松', 'type' => '耐力赛', 'status' => 'registering', 'date' => '2026年10月1日 8:00-16:00', 'city' => '苏州', 'location' => '苏州工业园区', 'organizer' => '智能机器人协会', 'hot' => 82, 'teams' => 40, 'icon' => '🏃', 'bg_color' => '#D1F5E0', 'detail' => '参赛机器人需自主完成20公里循迹赛道，考验续航与算法稳定性。', 'prize' => "🏆 冠军：20万元\n🥈 亚军：8万元\n🥉 季军：4万元", 'schedule' => "📌 8:00 签到\n📌 9:00 出发\n📌 14:00 颁奖", 'history' => '新增挑战组别，面向高校与企业开放。', 'sort' => 82],
        ];

        foreach ($events as $event) {
            Event::create($event);
        }

        DB::table('events')->count();
        echo 'EventSeeder: '.Event::count()." 场赛事已入库\n";
    }
}
