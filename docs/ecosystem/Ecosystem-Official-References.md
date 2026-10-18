# 赛事活动与动态资讯：官方实践研究及验收建议

研究日期：2026-10-18（Asia/Shanghai）。用途：为 summon-mecha 下一阶段工程规划提供依据，未开发功能、未跑写库测试、未联系外部机构。下文“来源事实”与“项目建议”分开；建议不代表外部赛事规则，也不代表本项目已有对应表或 API。

建议先建设可信的活动记录、报名和内容发布流程。赛季、赛项、场次、规则和成绩需要能追溯；资讯需要能说明是谁发布、依据什么来源、何时更正或撤回。优先复用现有 Laravel 模块、管理权限、审核、审计和消息能力，先盘点现状再决定增量。首期不纳入真钱票务结算、全球联赛编排、自动裁判、独立团队 RBAC、未经许可的内容抓取或转载。

## 一、赛事平台的官方事实与可用启发

以下页面访问日期均为 **2026-10-18**。不同平台的参赛资格、期限、证据和处罚规则互不通用。

| 编号 / 官方来源 | 来源事实 | 对本项目的建议（推导） |
| --- | --- | --- |
| E1 [RoboMaster 2026 异常情况及申诉公告](https://www.robomaster.com/zh-CN/resource/pages/announcement/1908) | 按赛项、站点或赛区、场次、申诉方、处理结果和详情公示；结果有维持原成绩和双方重赛。异常公示说明过暂停成绩确认并按具体规则条款核查。页头日期为 2026-03-21，不能据此推断每条事件的发生日期。 | 申诉关联具体场次和适用规则；成绩需有待确认、已发布、已终审、已修订的语义。人工裁决保留理由和修订记录，不通过直接改分抹除历史。 |
| E2 [FIRST FRC 2026 Season Materials](https://www.firstinspires.org/resources/library/frc/season-materials) | 赛季手册、Team Updates、往季档案和 Q&A 分开；提问权限按队授权，阅读可公开。翻译版不随更新持续修订，官方说明英文当前版具有权威性。明确允许外链但禁止重托管、再分发其页面资料。 | 记录赛季、文件版本、语言、权威来源和适用范围；报名记录接受的规则版本。第三方资料优先外链，不把公开可访问当成可复制授权。队伍身份和某次报名分开，复用已有账号及管理权限。 |
| E3 [FIRST FRC 当前赛季页](https://www.firstinspires.org/programs/frc/game-and-season) | 访问时页面已介绍 2027 赛季，并说明 2026 Q&A 已关闭、历史问题仍可检索；E2 资料页仍留有 Q&A 开放表述。 | 页面资料可能滞后。外部活动的开放状态应记录来源、核验时间和人工确认；不得把旧资料页的一句说明直接当成实时可报名状态。 |
| E4 [FIRST FTC 2025–2026 归档资料](https://ftc-resources.firstinspires.org/ftc/archive/2026/game)、[归档手册](https://ftc-resources.firstinspires.org/ftc/archive/2026/game/manual) | 归档资料按整本和章节标注更新版本、日期。手册第 13.4 节定义比赛结果问询流程及代表、时机；裁决职权分比赛与非比赛事项。该赛事对影像用于判罚有专门限制。这里是归档规则，不是对当前赛季最新版的认定。 | 申诉提交人、窗口、证据类型、裁决人由每届已公布规则配置；不能直接套用统一的“五分钟”或默认允许视频举证。无需首期实现一整套裁判组织系统。 |
| E5 [RECF / RobotEvents 发布活动指南（葡语公开版）](https://kb.roboticseducation.org/hc/pt/articles/4808321634199-Publicando-um-evento-em-RobotEvents-com) | 指南区分项目与赛季、公开时间、报名起止和活动时间；批准前不会因到达公开时间就自动上线。团队报名与候补分列；部分工作坊按个人报名。议程、资料、变更安排、结果及奖项有独立管理内容。 | 活动发布、报名和举办状态分开。比赛支持队伍报名，普通活动按需支持个人报名；容量、候补只在确有业务需求时实现。先做人工审阅发布的赛程/成绩，不立即集成 Tournament Manager。 |

E5 为官方自动翻译页面，页面本身提示可能有翻译误差；英文同名指南在本次访问中转到登录页，未读取登录后的内容。本研究仅借鉴稳定的信息结构，不据此宣称掌握 RobotEvents 2026 年全部现行政策或后台实现。

## 二、资讯和活动内容的官方事实与可用启发

以下页面访问日期均为 **2026-10-18**。

| 编号 / 官方来源 | 来源事实 | 对本项目的建议（推导） |
| --- | --- | --- |
| N1 [Open Robotics News](https://www.openrobotics.org/blog) | 官方新闻列表展示作者或机构及日期，涵盖技术发布和活动动态。公开页面不足以证明其内部审核流程或内容复制许可。 | 资讯展示作者、发布机构、来源及日期；活动与报道用关联关系连接，不能把报道发布时间当活动开始时间。站点开源技术背景不等于新闻图片和全文可自由转载。 |
| N2 [AP：Telling the Story](https://www.ap.org/about/news-values-and-principles/telling-the-story/)、[AP：News Values](https://www.ap.org/about/news-values-and-principles/news-values-introduction/) | AP 要求内容归属清晰，严重错误及时上报，更正须明确标识且让读者可见；其原则也要求广告可辨识。 | 分开原创、官方供稿、获准转载、链接摘要和商业合作；来源声明不能冒充平台独立核实。事实更正显示原因与时间，保留首次发布时间；商业合作显著标记。 |
| N3 [IPTC NewsML-G2 2.34 §2.7.2](https://www.iptc.org/std/NewsML-G2/2.34/specification/NewsML-G2-2.34-specification.html) | 发布状态区分 usable、withheld、canceled；暂缓内容不得公开，撤销为终态，不得继续使用内容。撤销后的身份、版本、状态等元数据与正文可靠性分开处理。 | 借鉴“暂缓”和“撤稿”的区别，撤稿不能被定时任务或重试自动恢复；停止所有公开分发路径。无需引入完整 NewsML XML 交换系统。 |
| N4 [Google：发布日期](https://developers.google.com/search/docs/appearance/publication-dates?hl=en)、[Article 元数据](https://developers.google.com/search/docs/appearance/structured-data/article) | 文档区分发布日期与修改日期，建议可见日期与结构化数据一致；作者可以是人或机构，且与发布者有不同字段语义。 | 原文发布时间、平台首次发布时间、更新时间和活动起止分字段；明确时区。只有实际支持公开 Web 内容时才评估 JSON-LD，不能假定 uni-app H5 哈希路由自动获得搜索收录。 |
| N5 [Google：Event 状态](https://developers.google.com/search/docs/appearance/structured-data/event) | 取消或延期的事件使用相应状态；取消时保留原日期等识别信息，已确定改期时更新新日期并标注改期。 | 取消、待定延期和确定改期分开；保留原安排与变更原因，用同一活动身份展示通知，不删除记录或编造未知新时间。 |
| N6 [网信办：《网络信息内容生态治理规定》](https://www.cac.gov.cn/2019-12/20/c_1578375159509309.htm) | 原文涉及平台主体责任、审核巡查和应急机制、违法信息处置记录、误导性标题及广告管理等。 | 复用内容审核和举报入口；人工复核、紧急下架、处理记录和责任人必须能落地，不能用机器分数代替全部审核责任。具体适用义务需另行确认。 |
| N7 [网信办：《互联网新闻信息服务管理规定》](https://www.cac.gov.cn/2017-05/02/c_1120902760.htm) | 第 2、5 条规定新闻信息范围与相应许可要求；第 15 条对适用服务的转载来源、原作者、原标题、编辑姓名、可追溯及著作权提出要求。 | “机器人资讯”这个栏目名称不能决定是否落入该业务范围。负责人应结合实际选题、主体和经营方式核定发布范围、来源及资质；工程预留来源和编辑追踪，不自行断言全部需要许可或全部豁免。 |

N6、N7 是本次读取的官方原文，不构成全部现行法规、地方要求和平台资质的核验。规划可以先细化自有活动公告和已获授权内容的工作流，正式开放发布前确认实际业务边界。

## 三 工程设计依据

以下官方工程资料于2026-10-18核对；其原则用于本项目设计，不能代替实现后的测试或认证。

| 官方资料 | 本项目采用的工程要求 |
|---|---|
| [Laravel 11悲观锁](https://laravel.com/docs/11.x/queries#pessimistic-locking) | 共用事务和锁顺序；当前读复检；数据库唯一约束最终防重；不能只调用lockForUpdate就宣称业务正确 |
| [OWASP文件上传](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) | 类型/签名/大小允许列表、生成存储名、权限校验、受控存储，不凭扩展名或客户端MIME放行 |
| [OWASP XSS防护](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html) | 富文本净化、上下文输出和安全URL策略；内容关键词审核不能替代脚本防护 |
| [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/) | 关键流程键盘可操作、名称/错误/焦点可理解，不只依赖颜色；作为验收参考，不提前宣称全面符合或取得认证 |

## 四 本项目的采用方式

按[产品技术方案](Ecosystem-Events-Content-Plan.md)和[工程任务及验收矩阵](Ecosystem-Engineering-Backlog.md)实施。外部材料用于确定能力边界和可追溯原则，不复制其全部赛制、许可结论或后台架构。撤稿原对象维持终态，不能换ID再分发旧正文；新纠正报道独立核实审核。机构账号认证、来源核验和内容使用权分别验证。
