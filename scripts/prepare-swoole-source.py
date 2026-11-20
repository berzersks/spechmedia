#!/usr/bin/env python3
"""Patch Swoole PECL source lists before TypePHP builds private PHP."""
from __future__ import annotations

import fcntl
import re
import subprocess
import sys
import urllib.request
from pathlib import Path

CACHE = Path.home() / ".typephp"
ORIGINAL = 'swoole_source_file="${ext_src_files} ${lib_src_files}"'
EXTENSION = 'PHP_NEW_EXTENSION(swoole, $swoole_source_file, $ext_shared,, "$EXTRA_CFLAGS", cxx)'
MARKER = "dnl SpechMedia: static Swoole source list for PHP_NEW_EXTENSION"

def patch_config(swoole: Path) -> bool:
    config = swoole / "config.m4"
    if not config.is_file():
        raise RuntimeError(f"Missing Swoole config: {config}")
    code = config.read_text(encoding="utf-8")
    if MARKER in code or ('swoole_source_file=""' in code and "PHP_NEW_EXTENSION(swoole, ext-src/" in code):
        return False
    files = sorted([p.relative_to(swoole).as_posix()
                    for directory in ("ext-src", "src")
                    for p in (swoole / directory).rglob("*.cc")])
    if len(files) < 50 or not any(p.startswith("src/") for p in files):
        raise RuntimeError(f"Incomplete Swoole source list: {len(files)}")
    if code.count(ORIGINAL) != 1 or code.count(EXTENSION) != 1:
        raise RuntimeError("Unexpected Swoole config.m4; aborting")
    code = code.replace(ORIGINAL, 'swoole_source_file=""', 1)
    code = code.replace(EXTENSION, MARKER + "\n    PHP_NEW_EXTENSION(swoole, "
                        + " ".join(files) + ' $swoole_source_file, $ext_shared,, "$EXTRA_CFLAGS", cxx)', 1)
    config.write_text(code, encoding="utf-8")
    print(f"SpechMedia: registered {len(files)} Swoole C++ sources in {config}", flush=True)
    return True

def prepare_pecl() -> None:
    pecl = CACHE / "pecl"
    pecl.mkdir(parents=True, exist_ok=True)
    with (pecl / ".spechmedia.lock").open("a+b") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        with urllib.request.urlopen("https://pecl.php.net/rest/r/swoole/stable.txt", timeout=60) as response:
            version = response.read().decode().strip()
        if not re.fullmatch(r"[0-9][A-Za-z0-9._-]*", version):
            raise RuntimeError(f"Unexpected PECL version: {version}")
        source = pecl / f"swoole-{version}"
        if not source.is_dir():
            archives = CACHE / "archives"
            archives.mkdir(parents=True, exist_ok=True)
            archive = archives / f"swoole-{version}.tgz"
            if not archive.is_file():
                with urllib.request.urlopen(f"https://pecl.php.net/get/swoole-{version}.tgz", timeout=60) as data, archive.open("wb") as target:
                    while chunk := data.read(1024 * 1024):
                        target.write(chunk)
            subprocess.run(["tar", "-xzf", str(archive), "-C", str(pecl)], check=True)
        patch_config(source)

def prepare_existing() -> None:
    for source in sorted((CACHE / "php-builder-src").glob("php-*/ext/swoole")):
        if patch_config(source):
            root = source.parent.parent
            print(f"SpechMedia: regenerating configure in {root}", flush=True)
            subprocess.run(["sh", "./buildconf", "--force"], cwd=root, check=True)

if __name__ == "__main__":
    try:
        prepare_pecl()
        prepare_existing()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print(f"SpechMedia: Swoole preparation failed: {error}", file=sys.stderr)
        sys.exit(1)
