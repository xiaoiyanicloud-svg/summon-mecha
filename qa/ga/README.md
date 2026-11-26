# GA 研究级门禁

此目录只放可复现实验与硬门禁，不存放伪造的 HIL 证据。

## 1. HIL 证据门禁

```powershell
$python='C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
& $python qa/ga/hil_evidence_validator.py <bundle>/evidence.json --json qa/ga/hil_validation_result.json
```

只有退出码 0 且 `passed=true` 的 bundle 才能作为某机型 HIL 证据候选。即使通过机器门禁，仍需独立人工复核；本工具不能证明测试真的在物理设备上发生。

拒绝：
- `claim_level=HIL/LIMITED_FIELD/GA` 却没有原始日志、重放脚本和场景文件哈希；
- observation source 是 `sdk_return`、`bt_halt`、`cancel_requested`、`cloud_ack`、`script_assertion`、`mock` 或 `sil`；
- 缺 E-Stop、看门狗、通信中断、电源中断、传感器失效、热降额、回滚失败、启动后崩溃场景；
- A2 作为 GA 目标；
- GA 仍有未解决物理限值或 open safety findings。

## 2. 30 分钟 soak

```powershell
$python='C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'
& $python qa/ga/soak_agent.py --duration-seconds 1800 --interval-seconds 2 --sample-seconds 10 --output qa/ga/soak_30m_result.json --timeline qa/ga/soak_30m_timeline.jsonl
```

soak 会以真实本地 HTTP 循环执行签名 v2 `install → execute → uninstall`，采样进程工作集、句柄、线程、临时文件、事件和 ACK。它仍只是软件 soak，不是 SIL/HIL。

## 3. 控制面 p95/p99/饱和点

```powershell
& $python qa/ga/benchmark_control_plane_saturation.py --main-requests 100 --level-requests 20 --levels 1,2,4,8 --output qa/ga/control_plane_saturation_result.json
```

PHP built-in server 结果属于开发类似基线，不得当作生产 FPM 结论。

## 4. 500MiB 流式

```powershell
& $python qa/ga/benchmark_stream_500mb.py
```

## 5. 解释纪律

- `SOFTWARE_SOAK_ONLY`、`SIL`、`HIL`、`LIMITED_FIELD`、`GA` 不得跳级。
- 任何 `UNKNOWN`、缺停止证据、HIL bundle 缺失或性能门禁失败，都必须保留为阻塞项。