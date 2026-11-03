"""加密、哈希、JCS(RFC 8785)与验签模块"""

import base64
import hashlib
import json
import subprocess
import tempfile
from typing import Any, Dict, Union

from zhjj_agent.constants import PROTOCOL_V1, PROTOCOL_V2


def _utf16_sort_key(s: str) -> bytes:
    """按 UTF-16BE 码元排序（RFC 8785 规则）。"""
    return s.encode("utf-16-be")


def format_es_number(val: Union[int, float]) -> str:
    """按 ECMAScript 标准格式化数字（RFC 8785 §3.2.2.3 规范表示）。
    精确符合 ECMAScript Number.prototype.toString(10) 规则：
    - 0 与 -0 均为 "0"
    - 1e-6 到 1e20 之间使用定点十进制表示（例如 1e-6 -> "0.000001"）
    - 指数表示法格式为 "1e-7", "1e+21"
    """
    if isinstance(val, bool):
        return "true" if val else "false"
    import math
    if math.isnan(val) or math.isinf(val):
        raise ValueError("NaN and Infinity are not permitted in JCS")
    if val == 0:
        return "0"
    sign = "-" if math.copysign(1.0, val) < 0 else ""
    val_abs = abs(val)
    if isinstance(val, int) or (isinstance(val, float) and val_abs.is_integer() and val_abs < 1e21):
        return f"{sign}{int(val_abs)}"

    s = str(val_abs)
    if "e" in s or "E" in s:
        mantissa, exp_s = s.lower().split("e")
        exp = int(exp_s)
    else:
        mantissa = s
        exp = 0

    if "." in mantissa:
        dot_idx = mantissa.index(".")
        digits = mantissa.replace(".", "")
        n = dot_idx + exp
    else:
        digits = mantissa
        n = len(digits) + exp

    digits = digits.lstrip("0")
    k = len(digits)

    # 6. If k <= n <= 21: return digits + '0' * (n - k)
    if k <= n <= 21:
        return sign + digits + ("0" * (n - k))
    # 7. If 0 < n <= 21: return digits[:n] + '.' + digits[n:]
    if 0 < n <= 21:
        return sign + digits[:n] + "." + digits[n:]
    # 8. If -6 < n <= 0: return '0.' + ('0' * -n) + digits
    if -6 < n <= 0:
        return sign + "0." + ("0" * -n) + digits

    exp_sign = "+" if (n - 1) >= 0 else "-"
    exp_val = str(abs(n - 1))
    if k == 1:
        return sign + digits + "e" + exp_sign + exp_val
    return sign + digits[0] + "." + digits[1:] + "e" + exp_sign + exp_val


def _jcs_serialize(obj: Any) -> str:
    if obj is None:
        return "null"
    if isinstance(obj, bool):
        return "true" if obj else "false"
    if isinstance(obj, (int, float)):
        return format_es_number(obj)
    if isinstance(obj, str):
        return json.dumps(obj, ensure_ascii=False, separators=(',', ':'))
    if isinstance(obj, dict):
        sorted_keys = sorted(obj.keys(), key=_utf16_sort_key)
        items = []
        for k in sorted_keys:
            key_str = json.dumps(str(k), ensure_ascii=False, separators=(',', ':'))
            val_str = _jcs_serialize(obj[k])
            items.append(f"{key_str}:{val_str}")
        return "{" + ",".join(items) + "}"
    if isinstance(obj, (list, tuple)):
        items = [_jcs_serialize(x) for x in obj]
        return "[" + ",".join(items) + "]"
    return json.dumps(obj, ensure_ascii=False, separators=(',', ':'))


def jcs_canonicalize(obj: Any) -> bytes:
    """RFC 8785 (JSON Canonicalization Scheme - JCS) 规范化编码。"""
    return _jcs_serialize(obj).encode("utf-8")


def canonical_v1(cmd: Dict[str, Any]) -> bytes:
    """v1 历史规范串：task_id|skill_id|version|package_hash|package_sha256|ts"""
    parts = [
        str(cmd.get("task_id") or ""),
        str(cmd.get("skill_id") or ""),
        str(cmd.get("version") or ""),
        str(cmd.get("package_hash") or ""),
        str(cmd.get("package_sha256") or ""),
        str(cmd.get("ts") or ""),
    ]
    return "|".join(parts).encode("utf-8")


def canonical_v2(signed: Dict[str, Any]) -> bytes:
    """v2 规范字节：
    UTF-8("summon-mecha/agent-task/v2") || 0x0A || UTF-8(JCS(signed))
    """
    prefix = b"summon-mecha/agent-task/v2\n"
    jcs_body = jcs_canonicalize(signed)
    return prefix + jcs_body


def verify_signature(data: Union[bytes, str], signature_b64: str, pubkey_pem: str) -> bool:
    """RSA-SHA256 签名核验。优先使用 cryptography 库，若不可用则回退 openssl CLI。"""
    if isinstance(data, str):
        msg = data.encode("utf-8")
    else:
        msg = data

    try:
        sig = base64.b64decode(signature_b64 or "")
    except Exception:
        return False

    if not sig:
        return False

    # 1. 尝试 cryptography 库
    try:
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import padding
        pub = serialization.load_pem_public_key(pubkey_pem.encode("utf-8"))
        pub.verify(sig, msg, padding.PKCS1v15(), hashes.SHA256())
        return True
    except ImportError:
        pass
    except Exception:
        return False

    # 2. 回退 openssl 命令行
    try:
        with tempfile.NamedTemporaryFile(delete=False) as kf:
            kf.write(pubkey_pem.encode("utf-8"))
            kpath = kf.name
        with tempfile.NamedTemporaryFile(delete=False) as sf:
            sf.write(sig)
            spath = sf.name
        
        proc = subprocess.run(
            ["openssl", "dgst", "-sha256", "-verify", kpath, "-signature", spath],
            input=msg,
            capture_output=True
        )
        return proc.returncode == 0
    except Exception:
        return False


def sign_data(data: Union[bytes, str], privkey_pem: str) -> str:
    """使用私钥对数据执行 RSA-SHA256 签名，返回 Base64 编码字符串。"""
    if isinstance(data, str):
        msg = data.encode("utf-8")
    else:
        msg = data

    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding
    priv = serialization.load_pem_private_key(privkey_pem.encode("utf-8"), password=None)
    sig = priv.sign(msg, padding.PKCS1v15(), hashes.SHA256())
    return base64.b64encode(sig).decode("ascii")


def sha256_file(filepath: str, chunk_size: int = 65536) -> str:
    """流式计算本地文件的 SHA-256 哈希。"""
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while True:
            chunk = f.read(chunk_size)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest().lower()


def sha256_bytes(data: bytes) -> str:
    """计算字节数据的 SHA-256 哈希。"""
    return hashlib.sha256(data).hexdigest().lower()
