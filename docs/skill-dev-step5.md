# 技能平台 · 步骤 5 交付说明（设备与机型）

> 日期：2026-10-13 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 5 步
> 状态：**机型库种子 + 兼容性校验接口 —— 已交付并实测通过**；**Admin 机型 CRUD —— 待做**。

---

## 一、已交付

### 1 · 机型库种子
`database/seeders/DeviceModelSeeder.php` → **18 个机型入库**（幂等，按 model_code updateOrInsert）。

| 分级 | 机型 |
|---|---|
| **active（P0 三家）** | 宇树 G1/Go2/H1/H2/B2、智元 A2/A3-Ultra/X2、众擎 PM01/T800 |
| **display_only（仅展示）** | 乐聚 Kuavo4Pro、傅利叶 GR-3、优必选 Walker S2、云深处 X30、BD Spot、1X NEO、Tesla Optimus、Figure 02 |

- G1/Go2/A2/A3/PM01 → `comm_channels=["agent","manual"]`（Agent 通道开发中）
- H1/H2/B2/X2/T800 → `["manual"]`
- display_only 且无通道的（特斯拉/Figure/1X）→ `[]`
- **`compute_platform` / `firmware_versions` 大量留 null**（厂商不公开）——校验逻辑须处理

### 2 · 兼容性校验（服务 + 接口）
| 文件 | 作用 |
|---|---|
| `Services/SkillDelivery/CompatibilityService.php` | 判定逻辑 |
| `SkillController::compatibility` | `GET /api/skills/{productId}/compatibility?deviceId=` |

**判定**：`可用通道 = 技能声明通道(delivery_channels) ∩ 机型可用通道(comm_channels)`

**「未知」降级（关键，实测有效）**：
- 设备未关联机型 → 不判"机型不匹配"，降级为"仅人工通道"+提示
- 固件版本未知 → **不判"固件过低"**，只提示"请确认固件版本"
- 设备离线 → 仅提示（影响 agent，不影响 manual）

---

## 二、实测结果

| 场景 | 结果 |
|---|---|
| 技能声明 `[agent,manual]` × 设备=**宇树G1** | `compatible=1, channels=[agent,manual]` ✅ |
| × 设备=**特斯拉 Optimus**(display_only, 无通道) | `compatible=0, "无可用通道"` ✅ |
| × 设备=**宇树H1**(仅 manual) | `compatible=1, channels=[manual]` + "固件信息未知"提示 ✅ |
| 设备未关联机型 | 降级"仅人工通道"，不误判 ✅ |
| 语法 / 路由 | 全过 / `api/skills/{productId}/compatibility` 已注册 ✅ |

---

## 三、待做（步骤 5 剩余）

**机型库 Admin CRUD**（Bagisto 后台 DataGrid + 表单 + 路由 + 菜单 + ACL）——ops 维护机型用。当前只能靠种子/SQL。**属 UI 工作量较大项，建议并入平台端后台（第 10 步）一起做**，或单独排。

---

## 四、附:种子数据分级依据
见 [device-model-seed.md](./device-model-seed.md)。分级对应计划 §0.5 兼容性承诺 L1/L2/L3：
- L1 可交付 → active + 有通道
- L3 仅展示 → display_only + 无/仅 manual
