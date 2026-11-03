#!/usr/bin/env python3
"""召唤机甲 · 设备端 Agent (zhjj_agent) 命令行入口"""

import os
import sys

# 将包路径加入 sys.path
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.insert(0, current_dir)

from zhjj_agent.main import main

if __name__ == "__main__":
    main()
