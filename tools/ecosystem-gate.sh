#!/usr/bin/env bash
# 生态赛事与资讯 · 一键门禁
#
# 用法:
#   bash tools/ecosystem-gate.sh                    # 契约 + preflight + 后端/Admin 测试 + 前端 Node
#   bash tools/ecosystem-gate.sh --with-browser     # 额外跑 G3 Admin 录分/发布演练 + G2/G3/G4 e2e（e2e 需已导出 H5）
#   bash tools/ecosystem-gate.sh --with-admin-browser  # 只加跑 G3 Admin 录分/发布演练（只需 Chrome）
#   bash tools/ecosystem-gate.sh --skip-phptest     # 只跑契约/preflight/Node
#
# 纪律:
#   · PHPUnit 只指向独立测试库（默认 summon_mecha_step10_test），绝不写 root；
#   · data-preflight 是只读命令，默认对 .env 的库执行；
#   · 任一步失败最终 exit 1，但会继续跑完其余步骤，便于一次看全。

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STORE="$ROOT/my-bagisto-store"
TEST_DB="${ECOSYSTEM_TEST_DB:-summon_mecha_step10_test}"

WITH_BROWSER=0
WITH_ADMIN_BROWSER=0
SKIP_PHPTEST=0
SKIP_NODE=0
SKIP_PREFLIGHT=0

for arg in "$@"; do
    case "$arg" in
        --with-browser) WITH_BROWSER=1 ;;
        --with-admin-browser) WITH_ADMIN_BROWSER=1 ;;
        --skip-phptest) SKIP_PHPTEST=1 ;;
        --skip-node) SKIP_NODE=1 ;;
        --skip-preflight) SKIP_PREFLIGHT=1 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2
            exit 2
            ;;
    esac
done

if [ -n "${PHP_BIN:-}" ] && [ -x "$PHP_BIN" ]; then
    :
elif command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
elif [ -x /d/php.exe ]; then
    PHP_BIN=/d/php.exe
elif [ -x "D:/php.exe" ]; then
    PHP_BIN="D:/php.exe"
else
    echo "未找到 PHP；请设置 PHP_BIN=/path/to/php" >&2
    exit 2
fi

mkdir -p "$ROOT/.claude"
LOG="$ROOT/.claude/ecosystem-gate-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG") 2>&1

PASS=0
FAIL=0

run_step() {
    local name="$1"
    shift
    echo ""
    echo "==> $name"
    if "$@"; then
        echo "PASS  $name"
        PASS=$((PASS + 1))
    else
        echo "FAIL  $name"
        FAIL=$((FAIL + 1))
    fi
}

php_step() {
    ( cd "$STORE" && "$PHP_BIN" "$@" )
}

php_test_step() {
    ( cd "$STORE" && DB_DATABASE="$TEST_DB" APP_ENV=testing "$PHP_BIN" artisan test "$@" )
}

echo "生态门禁"
echo "  repo : $ROOT"
echo "  php  : $PHP_BIN"
echo "  db   : $TEST_DB (PHPUnit)"
echo "  log  : $LOG"

run_step "构建契约门禁 ecosystem:contract-check" php_step artisan ecosystem:contract-check

if [ "$SKIP_PREFLIGHT" -eq 0 ]; then
    run_step "只读数据 preflight ecosystem:data-preflight" php_step artisan ecosystem:data-preflight
fi

if [ "$SKIP_PHPTEST" -eq 0 ]; then
    run_step "Shop Ecosystem 全量 PHPUnit" php_test_step packages/Webkul/Shop/tests/Feature/Ecosystem
    run_step "Admin ACL / G2 / G3 / G4 专项" php_test_step \
        packages/Webkul/Admin/tests/Feature/Catalog/EcosystemPublishingAclG1Test.php \
        packages/Webkul/Admin/tests/Feature/Catalog/G4MetricsOpsAdminTest.php \
        packages/Webkul/Admin/tests/Feature/Catalog/CompetitionAdminG3Test.php \
        packages/Webkul/Admin/tests/Feature/Catalog/CompetitionG3PermissionsTest.php \
        packages/Webkul/Admin/tests/Feature/Catalog/RegistrationAdminAclG2Test.php
fi

if [ "$SKIP_NODE" -eq 0 ]; then
    run_step "前端 Node 纯函数测试" bash -c "cd '$ROOT' && node --test tools/tests/ecosystem-g4.test.mjs tools/tests/ecosystem-public.test.mjs tools/tests/ecosystem-events.test.mjs"
fi

if [ "$WITH_BROWSER" -eq 1 ] || [ "$WITH_ADMIN_BROWSER" -eq 1 ]; then
    run_step "G3 Admin 录分/发布浏览器演练" bash -c "cd '$STORE' && node qa/ecosystem/admin-g3/run.mjs"
fi

if [ "$WITH_BROWSER" -eq 1 ]; then
    run_step "G2/G3/G4 真实浏览器 e2e" bash -c "cd '$STORE' && node qa/ecosystem/e2e/run.mjs"
fi

echo ""
echo "----"
echo "门禁结果: 通过 $PASS / 失败 $FAIL"
echo "日志: $LOG"

[ "$FAIL" -eq 0 ] || exit 1
