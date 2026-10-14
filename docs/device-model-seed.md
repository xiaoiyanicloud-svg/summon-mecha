# device_model 机型库 · 种子数据（调研采集）

> 日期：2026-10-13 ｜ 用途：`skill-supply-plan.md` §3.2 `device_model` 表种子
> 采集方式：官方文档/官网/官方 GitHub（高置信）＋ 发布会/媒体（中低）。**查不到一律留"未找到"，不填估值。**
> ⚠ **字段完整度提醒**：**通信/开发接口**字段齐全度 **远高于** **算力芯片型号** 与 **固件/OTA 版本号**——后两者是最大缺口，**种子库对这两列应允许"未知"**。

---

## 一、人形机器人（国内）

| 型号 | DOF | 尺寸/重量/负载 | 算力(x86/ARM) | 通信/开发接口 | OTA | SDK开放度 | 价格 |
|---|---|---|---|---|---|---|---|
| **宇树 G1** | 23（EDU 23–43） | 1320×450×200mm / ~35kg / 臂~2kg | 8核CPU；EDU可选 Orin(**ARM**) | **Unitree SDK2 = DDS/CycloneDDS**；unitree_ros2 | 云OTA | 基础封闭；**EDU开放** | ¥8.5万起 |
| 宇树 H1 | 19 | ~180cm / ~47kg | i5+i7(**x86**)；可选 Orin NX | 同上 | 云OTA | 封闭(行业版例外) | 未找到 |
| 宇树 H2 | 31 | 1820×456×218mm / ~70kg | i5+i7(**x86**)；可选 Thor | 同上 | 云OTA | **H2 EDU开放** | ¥19.9万 |
| **智元 远征A2旗舰** | 40 | 169cm / ~69kg / 单臂~2kg | 大脑=Jetson AGX Orin 64GB(**ARM**)+x86板 | **AimDK(ROS风格话题/服务)+AimRT**；TCP/IP | 智能OTA | 有限（大脑板可二开，**小脑运控板不可**） | 未找到 |
| 智元 A2青春版 | 23 | 169cm / ~63kg | 16核CPU(**x86**，无Orin) | AimDK A2-lite | 智能OTA | 有限 | 未找到 |
| **智元 灵犀X2** | 30/31/29 | 1310×460×210mm / ~39kg | RK3588(**ARM**)；高算力板 Orin NX 157TOPS | AimDK_X2；LinkSoul云 | 旗舰有/EDU无 | 较开放 | 10–40万 |
| **众擎 PM01** | 24 | 138cm | 应用=Jetson Orin NX(**ARM**)；运控=Intel N97(**x86**) | **ROS2 Humble(CycloneDDS, domain69)** + Native SDK | 未找到 | **高度开放** | 8.8万起 |
| 众擎 SE01 | 32 | 170cm | 未找到 | 未找到 | 未找到 | 品牌整体开放 | ~2–3万美元 |
| 乐聚 Kuavo4Pro | 28 | 166cm / 55kg | 下位i9(**x86**)；上位 AGX Orin (**ARM**) | 开源ROS(nMPC+WBC/OCS2)；CAN/以太网 | 版本环境变量 | 开放(MIT) | 未找到 |
| 傅利叶 GR-3 | 55 | 165cm / ~71kg | AMD Ryzen7(**x86**) | **FourierDDS**(Topic/Service/Action) | 未找到 | 开放(GitHub FFTAI) | 未找到 |
| 优必选 Walker S2 | 42 | 176cm / 70kg / 15kg | x86 + Jetson Orin | **ROS2 Humble+DDS, MQTT** | **有智能OTA** | 受限（SDK需申请） | ~$120k |
| 达闼 XR-1 | 34 | 158–160cm / ~65kg | **云脑架构**(算力在云) | 5G→HARIX云 | 未找到 | 半开放 | 未找到 |
| 小鹏 IRON | 62 | 178cm / 70kg | 自研图灵芯片(**ARM**) | 已发SDK(细节未公开) | 未找到 | 开放 | 未找到 |

## 二、四足机器人

| 型号 | DOF | 尺寸/重量/负载 | 算力 | 接口 | OTA | 开放度 | 价格 |
|---|---|---|---|---|---|---|---|
| **宇树 Go2** | 12 | 70×31×40cm / ~15kg / 7–8kg | 8核CPU；选Orin(**ARM**) | **SDK2=DDS**；unitree_ros2 | 云OTA | X部分/**EDU完全开放** | ¥9997起 |
| 宇树 B2 | 12 | ~60kg / 站立≥120kg | i5+i7(**x86**)；选 Orin NX | 同上 | 云OTA | 行业/科研开放 | 未找到 |
| 宇树 A2(2025) | 12 | ~35–42kg | 8核CPU(**x86**)+i7 | RS485/CAN/千兆网 | 连续OTA | 支持二开 | 未找到 |
| 云深处 绝影X30 | 12 | 56kg / ~15kg | **未找到** | 自研 **drdds** 通信SDK(含ROS2) | 未找到 | 开放SDK | 未找到 |
| **波士顿动力 Spot** | 12 | 33.8kg / 14kg | 未公开 | **Spot SDK v5.2.0**(Python+protobuf) | 有定期发布 | 开放(非完全开源) | 未找到 |

## 三、海外人形

| 型号 | DOF | 尺寸/重量 | 算力 | 接口/SDK | 价格 |
|---|---|---|---|---|---|
| 1X NEO | 未找到 | 168cm / 30kg / 举70kg | **Jetson Thor 2070 TFLOPS(ARM)** | 无公开SDK | **$20,000**(官方) |
| Tesla Optimus Gen2 | ~40(第三方) | ~1.73m / ~57kg | 未找到 | **无公开SDK(封闭)** | 目标$20–30k |
| Figure 02 | 44(第三方) | 1.68m / 60kg | 双低功耗NVIDIA GPU | **无公开SDK(封闭)** | 未找到 |
| BD Atlas(电动) | 未找到 | 未找到 | 未找到 | **无公开SDK**；接Orbit车队 | 未找到 |

## 四、可查性分级（决定兼容性校验数据能采多全）

- **① 可直接入库（高置信）**：宇树全系(G1/H1/H2/R1/Go2/B2/A2)、智元(远征A2/灵犀X2)、众擎PM01、乐聚Kuavo4Pro/5、傅利叶(GR-1/2/3/N1)、优必选Walker S2、云深处X30、BD Spot、1X NEO。
- **② 部分公开（需第三方交叉）**：智元A2-W、众擎SE01、优必选S1/S、达闼XR-1/XR-4、小鹏IRON、Tesla Optimus、Figure 02、云深处Lynx M20。
- **③ 几乎查不到**：BD 电动 Atlas、Tesla 算力/SDK、Figure SDK/价格、**所有厂商报价（除少数）**、**固件/OTA 版本号机制（仅宇树/优必选/智元有据）**。

## 五、兼容性判断要点（接口横向）

- **最开放**：宇树（DDS/CycloneDDS + SDK2 + unitree_ros2，编译支持 aarch64+x86_64）、众擎（ROS2 Humble + Native SDK 全开源）。
- **架构共性**：国内人形普遍 **x86(运控) + ARM(Jetson Orin/Thor，大脑/应用) 双平台异构**。
- **封闭**：Tesla / Figure / Atlas —— **无公开 SDK**，只能 L3 仅展示。
- **中间件路线**：宇树/众擎=CycloneDDS；傅利叶=FourierDDS；云深处=drdds；智元=AimRT+AimDK；乐聚=ROS1 开源。

## 六、数据缺口（务必留空而非填估值）

1. 各厂商**报价**（除宇树/1X NEO/众擎智元媒体价）；2. **固件/OTA 版本号机制**（多数无公开方案）；3. **算力芯片具体型号**（宇树仅"8核CPU/Orin/Thor"，云深处完全未公开）；4. 海外封闭厂商的算力/SDK/OTA/DOF；5. 原型机（灵犀X2-W、EVE）。

> **一句话**：**兼容性校验的"通信/接口"字段能采到八成以上；"算力型号/OTA 版本"只能采到一半——种子库必须允许这两列为"未知"，校验逻辑要能处理"未知"。**
