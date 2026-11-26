# 技能构建工具链镜像（WBS B5.5 / 第十批 T5）
#
# 用途：给 SkillBuildService 的构建器提供**可复现**的 PHP 工具链，并产出可核验的镜像内容摘要。
# 现状：平台当前并不在容器里构建；本镜像属于"独立工具链"目标形态。
#   本机 Docker daemon 未运行 → 真实构建与摘要均为 NOT_RUN（见
#   docs/skill-dev-step10-wbs-tenth-batch-20261105.md 的 BLOCKED 表）。
#
# 构建（需 Docker Desktop 已启动）：
#   docker build -f docker/build/Dockerfile -t summon-mecha/skill-build:local .
# 取摘要并注入平台（见 tools/build-image.ps1）：
#   SKILL_BUILD_IMAGE_REF=summon-mecha/skill-build:local
#   SKILL_BUILD_IMAGE_DIGEST=sha256:<64 hex>

FROM php:8.3-cli-bookworm

# 构建器实际用到的扩展：ZIP 打包/解包、mbstring 文本处理、pdo_mysql 读写构建记录。
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libonig-dev default-mysql-client \
    && docker-php-ext-install zip mbstring pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# 构建脚本（工具链脚本落在 tools/，镜像只负责运行环境）
WORKDIR /workspace

# 可复现性：固定时区与 locale 相关的非确定输入。
ENV TZ=Asia/Shanghai \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8
