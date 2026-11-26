"""A4.1 可重复故障注入点（failpoints）。

设计目标：**在任何指定边界让进程立刻消失**，而不是抛一个能被 `except Exception` 吞掉的异常。
`os._exit(86)` 等价于被内核杀掉：不执行 `finally`、不刷缓冲、不释放锁 —— 这正是我们要测的。
只有需要"在进程内观察"时才用 `ZHJJ_FAILPOINT_MODE=exception`。

九个边界与 WBS A4.1 一一对应：
    before_intent          写意图前（record_task_durable 之前）
    after_download         下载完成后
    after_unpack           解包后
    after_verify           校验后（prepare 通过）
    after_activate         激活后（激活指针已落盘）
    after_execution_start  执行启动后
    before_outbox          事件落 outbox 前
    after_send_before_ack  发送成功、ACK 落库前
    after_restart          重启自愈完成后

⚠ 仅测试使用：生产环境不设置 `ZHJJ_FAILPOINT` 时，`hit()` 只是一次字典查找。
"""

import os

FAILPOINTS = (
    "before_intent",
    "after_download",
    "after_unpack",
    "after_verify",
    "after_activate",
    "after_execution_start",
    "before_outbox",
    "after_send_before_ack",
    "after_restart",
)

#: 默认退出码；测试用它区分"命中了 failpoint"与"别的崩溃"。
EXIT_CODE = 86


class FailpointTriggered(BaseException):
    """用 BaseException：业务代码里的 `except Exception` 不得吞掉故障注入。"""


def armed(name: str) -> bool:
    return name in FAILPOINTS and os.environ.get("ZHJJ_FAILPOINT") == name


def hit(name: str) -> None:
    """如果当前环境命中该 failpoint：按模式退出或抛异常。"""
    if not armed(name):
        return

    if os.environ.get("ZHJJ_FAILPOINT_MODE") == "exception":
        raise FailpointTriggered(name)

    # 真实崩溃语义：不跑 finally、不释放锁、不刷日志缓冲。
    os._exit(EXIT_CODE)
