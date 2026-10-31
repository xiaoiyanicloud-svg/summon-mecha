# 技能市场占位内容清理（2026-10-21）

## 结论

已删除生态中心技能市场中的两块占位呈现：

- 列表末尾的「已展示全部匹配技能」；
- 「技能构想示例 · 不可购买」整块折叠区及其弹窗交互。

保留真实接口驱动的加载、异常、空状态、筛选、分页和「打开完整技能市场」入口。

## 改动

| 文件 | 改动 |
|---|---|
| `components/SkillMarket.vue` | 删除列表末尾提示与构想示例 UI；删除相关 import、状态、方法、样式；仅在 `hasMore` 时保留「加载更多」容器 |
| `components/EcoCenter.vue` | 删除已失效的技能 mock 共用注释 |
| `components/jiqi/skill-data.js` | 注释改为「能力方向参考数据（非市场供给）」，明确不得再用于市场兜底冒充可交易技能 |

未删除 `components/jiqi/skill-data.js` 本体：它仍被规划文档引用为机型适配/自营填货架参考；本次只切断其运行时展示用途。

## 实测证据

- `node --test tools/tests/skill-market.test.mjs`：9/9 通过。
- `D:/HBuilderX/cli.exe publish web --project D:/main`：编译成功，导出到 `D:/main/unpackage/dist/build/web`。
- 真实 Chrome，390×844，独立测试库 `summon_mecha_step10_test`：
  - 页面进入稳定空态「暂无已上架技能」；
  - 两个目标文案均不出现；
  - 无页面异常、无控制台 error；
  - 截图：`.claude/backup/skill-market-placeholder-cleanup-20261021/evidence/skill-market-cleaned-390.png`。
- `git diff --check`：通过。

## 回滚

从以下备份恢复对应文件后重新导出 H5：

```text
.claude/backup/skill-market-placeholder-cleanup-20261021/components/SkillMarket.vue
.claude/backup/skill-market-placeholder-cleanup-20261021/components/EcoCenter.vue
.claude/backup/skill-market-placeholder-cleanup-20261021/components/jiqi/skill-data.js
```

## 未做

- 未重跑 G2/G3/G4 全量浏览器 e2e；本次只影响首页技能市场呈现，已做 390px 定向浏览器烟测。
- 未删除参考数据文件本体，避免丢失后续机型适配/自营供给规划资料。
