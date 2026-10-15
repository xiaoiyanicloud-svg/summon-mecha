/**
 * 厂商配对参数注册表 —— 三厂「一键配对装机」所需的**唯一定义处**。
 *
 * 口径来源（不另立门户，逐条对齐现有代码与文档）：
 *   - docs/agent-adaptation-plan.md §2 落地判定表、§3.5 F1「引导装机」；
 *   - docs/skill-supply-plan.md §4.22（用户侧引导装机 / 一键配对小工具）；
 *   - docs/skill-user-tutorial.md 第 2–3 步（IP / 账号 / 口令）；
 *   - 与小程序页 pages/mine/agent-setup.vue 的 SSH 参数**保持一致**（那边是当前
 *     C 端唯一的装机引导真相源，改这里前先对齐它，否则图文步骤与工具会打架）。
 *
 * ⚠ 这些 IP / 账号 / 默认口令是各厂**出厂默认值**，只用于「同一网段内直连」的配对起步。
 *   真机实测前，它们一律是**文档口径，不是已验证事实** —— 见 docs/skill-remaining-closure.md
 *   B-14 卡点：真机验证依赖 E7（外部）。
 *
 * ⚠ 红线：本工具只做「直连机器人 → 装我们自己的 Agent」，**不接入、不篡改任何厂商云**
 *   （宇树 EULA 红线）。
 */

/** 网卡默认掩码（三厂直连都是 /24）。 */
export const DEFAULT_MASK = '255.255.255.0';

export const VENDORS = {
	unitree: {
		key: 'unitree',
		name: '宇树',
		// 机器人出厂默认 IP / 登录账号（unitree 基础版出厂禁用 SSH，需 EDU/开发版）。
		robotIp: '192.168.123.164',
		sshUser: 'unitree',
		sshPort: 22,
		// 出厂默认口令「123」太弱，工具会强制提示先改（§4.22 F4）。
		sshPassword: '123',
		// 电脑侧建议地址：与机器人同网段、且不撞机器人自身。
		hostIp: '192.168.123.100',
		subnetPrefix: '192.168.123',
		installDir: '/opt/zhjj-agent',
		notice: '⚠ 宇树基础版出厂禁用 SSH，需 EDU/开发版；开发者模式导致的损坏不在保修范围。',
	},
	agibot: {
		key: 'agibot',
		name: '智元',
		robotIp: '192.168.2.50',
		sshUser: 'agi',
		sshPort: 22,
		sshPassword: null, // 智元开发账号自带 sudo，口令随机器；工具不预置。
		hostIp: '192.168.2.100',
		subnetPrefix: '192.168.2',
		installDir: '/opt/zhjj-agent',
		notice: '⚠ 智元需开放二开机型；Agent 会装在 Orin 侧，遵守其硬约束（不碰 x86 运控板、不用宿主机 apt）。',
	},
	engineai: {
		key: 'engineai',
		name: '众擎',
		robotIp: '192.168.0.162',
		sshUser: 'ubuntu',
		sshPort: 22,
		sshPassword: 'ubuntu',
		hostIp: '192.168.0.100',
		subnetPrefix: '192.168.0',
		installDir: '/opt/zhjj-agent',
		notice: '⚠ 众擎请连应用算力单元（Jetson），默认口令 ubuntu 请先改；脚本不改动原厂 robotics.service。',
	},
};

/** 厂商 key 列表（供 CLI --list-vendors 与参数校验）。 */
export function vendorKeys() {
	return Object.keys(VENDORS);
}

/**
 * 取某厂商配置；未知 key 直接抛错（不静默兜底成宇树 —— 那会把用户装错机器）。
 */
export function getVendor(key) {
	const v = VENDORS[key];
	if (!v) {
		throw new Error(`未知厂商 "${key}"，可选：${vendorKeys().join(' / ')}`);
	}
	return v;
}
