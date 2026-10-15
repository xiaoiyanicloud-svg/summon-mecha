<?php

namespace Webkul\Shop\Http\Controllers;

use Illuminate\Http\Response;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * Agent 安装脚本端点(v3.4 步骤 8)—— 纯文本 GET,不走 /api 前缀。
 *
 * 为什么不放 public/ 静态文件:
 *   1. 平台公钥是**运行期**生成的(首次签名时才有),不能预置成静态文件;
 *   2. install.sh 里的平台地址要按环境(config('app.url'))烤进去。
 *
 * 三个端点:
 *   GET agent/setup.sh            → 安装脚本(内含 __SERVER__ 已替换)
 *   GET agent/platform_public.pem → 平台公钥(Agent 验签用)
 *   GET agent/agent.py            → 参考 Agent 本体
 *
 * ⚠ 脚本路径叫 setup.sh 而非 install.sh:Bagisto Installer 的 CanInstall 中间件
 *   会把任何含 `/install` 的路径重定向到 /admin。改名是绕开它,不是审美。
 *
 * ⚠ 这里是**参考实现**,用途是让"下发链路"可被平台侧端到端验证(模拟设备)。
 *   生产 Agent(装到宇树/智元/众擎真机上)由设备端团队实现,契约与此一致。
 */
class AgentSetupController extends Controller
{
    public function __construct(protected SigningService $signing) {}

    /**
     * 安装脚本。用户按 agent-setup 页给的命令执行:
     *   curl -fsSL <server>/agent/install.sh | bash -s -- --token <TOKEN>
     * (⚠ `--token` 必须经 `bash -s --` 传入,否则被 bash 自己吃掉。)
     */
    public function installScript(): Response
    {
        $script = str_replace('__SERVER__', rtrim(config('app.url'), '/'), self::INSTALL_SH);

        return response($script, 200, [
            'Content-Type'  => 'application/x-sh; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 平台公钥(给 Agent 验签)。轮换时 Agent 侧需支持多公钥(计划 F3)。
     */
    public function publicKey(): Response
    {
        return response($this->signing->publicKeyPem(), 200, [
            'Content-Type'  => 'application/x-pem-file',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 参考 Agent 本体(Python 3,零依赖:标准库即可,验签优先 cryptography、缺则回退 openssl CLI)。
     *
     * ⚠ 脚本里的错误码不手写:经占位符替换成 `DeployTask::ERR_*` 常量 ——
     *   nowdoc 里不能插值,这与 `installScript()` 替换 `__SERVER__` 是同一手法。
     *   手写字面量的后果很实在:平台改了码,脚本还在报旧码,而设备端会照旧上报。
     */
    public function agentPy(): Response
    {
        $script = str_replace(
            ['__ERR_SIG_INVALID__', '__ERR_PKG_MISSING__'],
            [DeployTask::ERR_SIG_INVALID, DeployTask::ERR_PKG_MISSING],
            self::AGENT_PY,
        );

        return response($script, 200, [
            'Content-Type'  => 'text/x-python; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 安装脚本。nowdoc 写法,不做 PHP 插值(脚本自带 $ 变量)。
     */
    protected const INSTALL_SH = <<<'SH'
#!/usr/bin/env bash
# 召唤机甲 · Agent 安装脚本(参考实现)
# 用法: curl -fsSL <SERVER>/agent/install.sh | bash -s -- --token <TOKEN>
set -euo pipefail

SERVER="__SERVER__"
TOKEN=""
INSTALL_DIR="/opt/zhjj-agent"

while [ $# -gt 0 ]; do
  case "$1" in
    --token)       TOKEN="${2:-}"; shift 2 ;;
    --server)      SERVER="${2:-}"; shift 2 ;;
    --install-dir) INSTALL_DIR="${2:-}"; shift 2 ;;
    *) echo "未知参数: $1"; exit 1 ;;
  esac
done

if [ -z "$TOKEN" ]; then
  echo "缺少 --token。请在「我的设备 → 安装 Agent」中获取完整安装命令。"
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "需要 python3(三类机型算力单元通常自带)。"
  exit 1
fi

echo "==> 安装目录: $INSTALL_DIR"
mkdir -p "$INSTALL_DIR"

echo "==> 下载 Agent 与平台公钥"
curl -fsSL "$SERVER/agent/agent.py"            -o "$INSTALL_DIR/agent.py"
curl -fsSL "$SERVER/agent/platform_public.pem" -o "$INSTALL_DIR/platform_public.pem"

echo "==> 换取设备身份(enroll)"
ENROLL_JSON="$(curl -fsSL -X POST "$SERVER/api/agent/enroll" \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"${TOKEN}\"}")"

python3 - "$INSTALL_DIR" "$SERVER" "$ENROLL_JSON" <<'PY'
import json, pathlib, sys
install_dir, server, payload = sys.argv[1], sys.argv[2], sys.argv[3]
data = json.loads(payload)["data"]
cfg = {
    "server_url": server,
    "did": data["did"],
    "agent_key": data["agent_key"],
    # per-device 凭据(§4.22 F1 / B-12)—— 有则优先于共享 agent_key。
    "device_secret": data.get("device_secret"),
    "public_key": str(pathlib.Path(install_dir) / "platform_public.pem"),
    "public_key_inline": data.get("server_public_key"),
    "poll_interval": 5,
}
pathlib.Path(install_dir, "config.json").write_text(json.dumps(cfg, indent=2))
print("==> DID =", data["did"])
PY

echo "==> 启动 Agent"
if command -v systemctl >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
  cat > /etc/systemd/system/zhjj-agent.service <<UNIT
[Unit]
Description=ZhaoHuanJiJia Agent
After=network-online.target

[Service]
ExecStart=/usr/bin/env python3 ${INSTALL_DIR}/agent.py --config ${INSTALL_DIR}/config.json
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable --now zhjj-agent
  echo "==> 已作为 systemd 服务启动: zhjj-agent"
else
  nohup python3 "${INSTALL_DIR}/agent.py" --config "${INSTALL_DIR}/config.json" \
    > "${INSTALL_DIR}/agent.log" 2>&1 &
  echo "==> 已在后台启动(无 systemd);日志: ${INSTALL_DIR}/agent.log"
fi

echo "==> 完成。回到「我的设备」应显示「已配对」,即可在技能市场点「一键下发」。"
SH;

    /**
     * 参考 Agent。nowdoc 写法,不做 PHP 插值。
     */
    protected const AGENT_PY = <<<'PY'
#!/usr/bin/env python3
"""召唤机甲 · 参考 Agent(HTTP 轮询传输)。

这是**参考实现**,用于平台侧端到端联调(当"模拟设备"跑),不是生产 Agent 本体。
生产 Agent(装到宇树 G1 / 智元 / 众擎真机上)由设备端团队实现,契约与此一致:

  enroll  → GET  /api/agent/commands?did=<did>   (轮询即心跳,无需单独高频心跳)
          → 按 canonical 串验签,不通过则拒绝安装
          → POST /api/agent/evt {task_no, did, phase: downloading|installing|download_failed|done|failed}

canonical 串 = '|'.join([task_id, skill_id, version, package_hash or '', ts])
签名 = RSA-SHA256(平台私钥) → 用 platform_public.pem 验签。

⚠ 服务端一律回 X1 信封 {success, code, message, data}。**回报可能被拒**
  (例如任务已 success 又报 failed → 409 TASK_TRANSITION_INVALID)。这里是机器消费者:
  必须看 `success`/`code`,不能假设 HTTP 2xx 就等于"服务端接受了",否则端云状态
  会静默分叉。被拒如实打印,不崩进程(见 evt())。

⚠ package_url 为空 → dry install(只走状态机,不拉真实包)。此时**必须**如实上报
  error_code=PKG_MISSING:服务端据此把这次 success 认成"空跑"(不落安装事实、不计入
  灰度成功率)。报成普通 done 等于凭空造一条"已安装"。
"""
import argparse
import base64
import json
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

# 参考 Agent 自身版本(§4.22 F1 自更新 / B-13)。真机 Agent 应把它随心跳上报。
AGENT_VERSION = "0.1.0"


def http(method, url, payload=None, headers=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Content-Type", "application/json")
    req.add_header("Accept", "application/json")
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    with urllib.request.urlopen(req, timeout=15) as r:
        body = r.read().decode() or "{}"
        return json.loads(body)


def canonical(cmd):
    return "|".join([
        str(cmd.get("task_id") or ""),
        str(cmd.get("skill_id") or ""),
        str(cmd.get("version") or ""),
        str(cmd.get("package_hash") or ""),
        str(cmd.get("ts") or ""),
    ])


def verify(cmd, pubkey_pem):
    """RSA-SHA256 验签。优先 cryptography 库,缺失则回退 openssl CLI。"""
    msg = canonical(cmd).encode()
    try:
        sig = base64.b64decode(cmd.get("signature") or "")
    except Exception:
        return False

    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import padding
        pub = serialization.load_pem_public_key(pubkey_pem.encode())
        pub.verify(sig, msg, padding.PKCS1v15(), hashes.SHA256())
        return True
    except ImportError:
        pass
    except Exception:
        return False

    # 回退:openssl CLI
    try:
        with tempfile.NamedTemporaryFile(delete=False) as k:
            k.write(pubkey_pem.encode())
            kpath = k.name
        with tempfile.NamedTemporaryFile(delete=False) as s:
            s.write(sig)
            spath = s.name
        p = subprocess.run(
            ["openssl", "dgst", "-sha256", "-verify", kpath, "-signature", spath],
            input=msg, capture_output=True,
        )
        return p.returncode == 0
    except Exception:
        return False


def evt(base, payload, headers):
    """上报一步状态。

    ⚠ 机器消费者:服务端回的是 X1 信封,**回报可能被拒**(409 TASK_TRANSITION_INVALID /
    404 TASK_NOT_FOUND)。必须看 success/code —— 只判断"请求发出去了"会让端侧以为
    报告成功、云侧状态没动,两边静默分叉。被拒就如实打印并继续,不因一条被拒的
    回报崩掉整个进程(那样整台设备就下线了,更糟)。
    """
    def denied(tag, code, message):
        print("[agent] %s 回报 %s 被服务端拒绝: %s %s" % (
            payload.get("task_no"), tag, code, message), flush=True)

    try:
        body = http("POST", "%s/api/agent/evt" % base, payload, headers)
    except urllib.error.HTTPError as e:
        # 4xx/5xx 的响应体就是信封 —— 把它读出来,否则只能看到一句 "HTTP 409"
        try:
            body = json.loads(e.read().decode() or "{}")
        except Exception:
            body = {}
        denied("HTTP %s" % e.code, body.get("code"), body.get("message"))
        return
    except Exception as e:
        print("[agent] %s 回报 %s 失败: %s" % (payload.get("task_no"), payload.get("phase"), e), flush=True)
        return

    if isinstance(body, dict) and body.get("success") is False:
        denied(payload.get("phase"), body.get("code"), body.get("message"))


def check_self_update(base, did, headers):
    """向平台问一次 Agent 自更新工件(§4.22 F1 / B-13)。

    ⚠ 真机 Agent 的"下载 → 验签 → 替换 → 失败回滚"是**设备侧**行为,本参考实现**没做**
       (无真机,未验证)。这里只**打印**平台回的工件,证明端云契约通、且服务端只给更高版本。
    """
    try:
        resp = http("GET", "%s/api/agent/self-update?did=%s&agent_version=%s"
                    % (base, did, AGENT_VERSION), headers=headers)
    except Exception as e:
        print("[agent] 自更新查询失败(忽略): %s" % e, flush=True)
        return

    data = resp.get("data") or {}
    if data.get("update_available"):
        print("[agent] 有新版 Agent %s(签名已下发;验签/替换待真机)" % data.get("version"), flush=True)
    else:
        print("[agent] Agent 自更新:%s" % data.get("reason"), flush=True)


def run(config):
    base = config["server_url"].rstrip("/")
    did = config["did"]

    # 鉴权:优先 per-device 凭据(§4.22 F1 / B-12),否则退回共享密钥(过渡)。
    # ⚠ 证书形态(用设备私钥对每个请求签名)是目标形态 —— 待真机 Agent 实现。
    if config.get("device_secret"):
        headers = {"X-Agent-Did": did, "X-Agent-Secret": config["device_secret"]}
    else:
        headers = {"X-Agent-Key": config["agent_key"]}

    pubkey_pem = config.get("public_key_inline")
    if not pubkey_pem and config.get("public_key"):
        with open(config["public_key"]) as f:
            pubkey_pem = f.read()

    interval = int(config.get("poll_interval", 5))
    print("[agent] 启动 did=%s server=%s version=%s" % (did, base, AGENT_VERSION), flush=True)

    check_self_update(base, did, headers)

    while True:
        try:
            resp = http("GET", "%s/api/agent/commands?did=%s" % (base, did), headers=headers)
        except urllib.error.HTTPError as e:
            print("[agent] 拉取失败 HTTP %s;%ss 后重试" % (e.code, interval), flush=True)
            time.sleep(interval)
            continue
        except Exception as e:
            print("[agent] 拉取异常 %s;%ss 后重试" % (e, interval), flush=True)
            time.sleep(interval)
            continue

        for cmd in resp.get("data") or []:
            task_no = cmd.get("task_id")

            if not verify(cmd, pubkey_pem):
                print("[agent] %s 验签失败,拒绝安装" % task_no, flush=True)
                evt(base, {"task_no": task_no, "did": did, "phase": "failed",
                           "error_code": "__ERR_SIG_INVALID__", "error_msg": "签名校验未通过"},
                    headers)
                continue

            print("[agent] %s 验签通过 op=%s" % (task_no, cmd.get("operation")), flush=True)

            evt(base, {"task_no": task_no, "did": did, "phase": "downloading"}, headers)
            evt(base, {"task_no": task_no, "did": did, "phase": "installing"}, headers)

            if cmd.get("package_url"):
                print("[agent] 下载 %s" % cmd["package_url"], flush=True)
                # 这里没有真实下载器的位置 —— 真机 Agent 在此拉包,失败时上报
                # phase="download_failed"(可重试)而不是 done。
                evt(base, {"task_no": task_no, "did": did, "phase": "done"}, headers)
            else:
                print("[agent] package_url 为空 → dry install(只走状态机,不拉真实包)", flush=True)
                # ⚠ 空跑必须打标:不带码的 done 会让服务端落成一条"已安装",
                #   凭空造出安装事实并污染灰度成功率。
                evt(base, {"task_no": task_no, "did": did, "phase": "done",
                           "error_code": "__ERR_PKG_MISSING__",
                           "error_msg": "未分发技能包(dry install),仅走通状态机"}, headers)

            print("[agent] %s 完成" % task_no, flush=True)

        time.sleep(interval)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", required=True, help="install.sh 生成的 config.json")
    args = ap.parse_args()
    with open(args.config) as f:
        run(json.load(f))


if __name__ == "__main__":
    main()
PY;
}
