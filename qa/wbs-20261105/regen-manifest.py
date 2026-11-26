"""重新生成 qa/wbs-20261105/evidence-manifest.json（只收录该目录**顶层文件**，排除自身）。

用法（在仓库根目录）：
    python qa/wbs-20261105/regen-manifest.py
"""

import hashlib
import json
import os

DIR = os.path.dirname(os.path.abspath(__file__))


def sha256(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def main() -> None:
    entries = []
    for name in sorted(os.listdir(DIR)):
        path = os.path.join(DIR, name)
        if not os.path.isfile(path) or name == "evidence-manifest.json" or name == os.path.basename(__file__):
            continue
        entries.append({"name": name, "sha256": sha256(path), "size": os.path.getsize(path)})

    manifest = os.path.join(DIR, "evidence-manifest.json")
    with open(manifest, "w", encoding="utf-8") as handle:
        json.dump(entries, handle, ensure_ascii=False, indent=4)

    print(f"entries={len(entries)} manifest_sha256={sha256(manifest)}")


if __name__ == "__main__":
    main()
