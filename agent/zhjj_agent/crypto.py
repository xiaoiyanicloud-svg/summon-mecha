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


def jcs_canonicalize(obj: Any) -> bytes:
    """RFC 8785 (JSON Canonicalization Scheme - JCS) 规范化编码。
    
    规则：
    1. 对象键按 UTF-16 码元顺序升序排序
    2. 无多余空白字符（separators=(',', ':')）
    3. 浮点数/整数紧凑表示
    4. 严格输出 UTF-8 字节
    """
    def _normalize(item: Any) -> Any:
        if isinstance(item, dict):
            # 递归排序所有键
            sorted_keys = sorted(item.keys(), key=_utf16_sort_key)
            return {k: _normalize(item[k]) for k in sorted_keys}
        elif isinstance(item, (list, tuple)):
            return [_normalize(x) for x in item]
        return item

    normalized = _normalize(obj)
    # separators=(',', ':') 保证 token 间无空格，ensure_ascii=False 输出纯 UTF-8
    canonical_json_str = json.dumps(
        normalized,
        ensure_ascii=False,
        separators=(',', ':'),
        allow_nan=False
    )
    return canonical_json_str.encode("utf-8")


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
