#!/usr/bin/env bash
# API 回归体检:一键验证核心接口健康(用法: bash tools/api-regression.sh [BASE_URL])
BASE="${1:-http://127.0.0.1:8000}"
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1 (期望 $2, 实际 $3)"; fi }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }

check "tob-catalog 200"  200 "$(code "$BASE/api/tob-catalog")"
check "scenes 200"       200 "$(code "$BASE/api/scenes")"
check "categories 200"   200 "$(code "$BASE/api/categories?limit=200")"
check "非法类型 422"     422 "$(code -X POST "$BASE/api/tob-order" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"type":"hack","product_id":6,"name":"reg","phone":"13800000000","qty":1,"city":"a","address":"b"}')"
check "缺手机号 422"     422 "$(code -X POST "$BASE/api/tob-order" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"type":"rent","product_id":6,"qty":1,"city":"a","address":"b"}')"

LEAK=$(curl -s "$BASE/api/tob-catalog" | grep -c "LEAD-CUSTOM" || true)
check "锚点商品无泄漏"   0 "$LEAK"

R=$(curl -s -X POST "$BASE/api/tob-order" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"type":"custom","name":"APIRegression","phone":"13800000000","product_name":"regression-lead","qty":1}')
echo "$R" | grep -q '"success":true' && check "线索下单 success" OK OK || check "线索下单 success" OK "FAIL: $R"

echo "----"
echo "回归结果: 通过 $PASS / 失败 $FAIL"
[ $FAIL -eq 0 ] || exit 1
