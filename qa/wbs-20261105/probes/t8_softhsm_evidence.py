"""T8 · SoftHSM2 真实 PKCS#11 调用演练（A7-HSM-01/02/03）。

诚实边界（必须随结果一起引用）：
  · 这是 **软件 HSM**（SoftHSM2 2.5.0 portable，PKCS#11 v2.40 实现），
    不是硬件 HSM，也不是云 KMS。等级只能是 **PROVEN_LOCAL**。
  · 本脚本只证明"私钥由 HSM 生成且不可导出、签名由 HSM 完成、销毁后签名失败"，
    **不证明** 生产 KMS/HSM 已就绪。

运行（用项目指定解释器，显式注入 pylibs 路径）：
    $env:SOFTHSM2_CONF = "D:\\main\\.tmp-b10\\softhsm2\\conf\\softhsm2.conf"
    python.exe -I qa/wbs-20261105/probes/t8_softhsm_evidence.py
"""

import json
import sys
import time

PYLIBS = r"D:\main\.tmp-b10\pylibs"
LIB = r"D:\main\.tmp-b10\softhsm2\pkg\SoftHSM2\lib\softhsm2-x64.dll"
PIN = "1234"
LABEL = "zhjj-t8"
KEY_LABEL = "zhjj-signing-key"
OUT = r"D:\main\qa\wbs-20261105\b10-t8-softhsm.json"

sys.path.insert(0, PYLIBS)

import pkcs11  # noqa: E402
from pkcs11 import Attribute, KeyType, Mechanism  # noqa: E402

from cryptography.hazmat.primitives import hashes, serialization  # noqa: E402
from cryptography.hazmat.primitives.asymmetric import padding, rsa  # noqa: E402

CANONICAL = b"did_zhjj_t8|1700000000|GET|api/agent/commands"

results = {
    "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
    "library": LIB,
    "token_label": LABEL,
    "evidence_level": "PROVEN_LOCAL",
    "note": "Software HSM (SoftHSM2 2.5.0 / PKCS#11). NOT hardware HSM, NOT production KMS.",
    "claims": {},
}

lib = pkcs11.lib(LIB)
token = lib.get_token(token_label=LABEL)

with token.open(user_pin=PIN, rw=True) as session:
    pub, priv = session.generate_keypair(
        KeyType.RSA,
        2048,
        label=KEY_LABEL,
        store=True,
        public_template={
            Attribute.PUBLIC_EXPONENT: b"\x01\x00\x01",
            Attribute.MODULUS_BITS: 2048,
            Attribute.VERIFY: True,
        },
        private_template={
            Attribute.SENSITIVE: True,
            Attribute.EXTRACTABLE: False,
            Attribute.SIGN: True,
        },
    )

    def as_int(value):
        return value if isinstance(value, int) else int.from_bytes(value, "big")

    modulus = as_int(pub[Attribute.MODULUS])
    exponent = as_int(pub[Attribute.PUBLIC_EXPONENT])
    rsa_pub = rsa.RSAPublicNumbers(exponent, modulus).public_key()
    public_pem = rsa_pub.public_bytes(
        serialization.Encoding.PEM,
        serialization.PublicFormat.SubjectPublicKeyInfo,
    ).decode()

    # ── A7-HSM-01 · 私钥不可导出 ─────────────────────────────────────────────
    sensitive = bool(priv[Attribute.SENSITIVE])
    extractable = bool(priv[Attribute.EXTRACTABLE])
    export_blocked = False
    export_error = ""
    try:
        # RSA 私钥的敏感分量；SENSITIVE=True / EXTRACTABLE=False 时 HSM 必须拒绝。
        _ = priv[Attribute.PRIVATE_EXPONENT]
    except Exception as exc:  # noqa: BLE001 - 记录真实异常类型
        export_blocked = True
        export_error = f"{type(exc).__name__}: {exc}"

    pem_is_public_only = public_pem.startswith("-----BEGIN PUBLIC KEY-----") and "PRIVATE KEY" not in public_pem

    claims_01 = {
        "sensitive": sensitive,
        "extractable": extractable,
        "private_component_export_blocked": export_blocked,
        "private_component_export_error": export_error,
        "exported_pem_public_only": pem_is_public_only,
    }
    results["claims"]["A7-HSM-01"] = {
        "desc": "密钥在 HSM 内生成，进程内拿不到私钥明文（导出的 PEM 只有公钥）",
        "detail": claims_01,
        "pass": sensitive and not extractable and export_blocked and pem_is_public_only,
    }

    # ── A7-HSM-02 · 签名由 HSM 完成，验签通过 ─────────────────────────────────
    signature = priv.sign(CANONICAL, mechanism=Mechanism.SHA256_RSA_PKCS)
    verify_error = ""
    verified = False
    try:
        rsa_pub.verify(signature, CANONICAL, padding.PKCS1v15(), hashes.SHA256())
        verified = True
    except Exception as exc:  # noqa: BLE001
        verify_error = f"{type(exc).__name__}: {exc}"

    results["claims"]["A7-HSM-02"] = {
        "desc": "签名由 HSM 完成，验签通过",
        "detail": {
            "signature_b64_len": len(signature),
            "verified_outside_hsm_with_public_key": verified,
            "verify_error": verify_error,
        },
        "pass": verified,
    }

    # ── A7-HSM-03 · 销毁（吊销）后 HSM 内签名调用失败 ─────────────────────────
    priv.destroy()
    post_destroy_sign_failed = False
    post_destroy_error = ""
    try:
        # 句柄已失效：C_Sign 应返回 CKR_OBJECT_HANDLE_INVALID 等错误。
        priv.sign(CANONICAL, mechanism=Mechanism.SHA256_RSA_PKCS)
    except Exception as exc:  # noqa: BLE001
        post_destroy_sign_failed = True
        post_destroy_error = f"{type(exc).__name__}: {exc}"

    results["claims"]["A7-HSM-03"] = {
        "desc": "吊销（销毁私钥对象）后 HSM 内签名调用失败",
        "detail": {
            "sign_after_destroy_failed": post_destroy_sign_failed,
            "sign_after_destroy_error": post_destroy_error,
        },
        "pass": post_destroy_sign_failed,
    }

all_pass = all(c["pass"] for c in results["claims"].values())
results["all_pass"] = all_pass

with open(OUT, "w", encoding="utf-8") as handle:
    json.dump(results, handle, ensure_ascii=False, indent=2)

for cid, claim in results["claims"].items():
    print(f"{cid}: pass={claim['pass']}  {claim['detail']}")
print(f"ALL_PASS={all_pass}")
print(f"WROTE {OUT}")

sys.exit(0 if all_pass else 1)
