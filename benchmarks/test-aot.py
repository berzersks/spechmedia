#!/usr/bin/env python3
"""Run the PHP test suites as native binaries with the same assertions."""
from pathlib import Path
from tempfile import mkdtemp
import subprocess

root = Path(__file__).resolve().parents[1]
(root / "build").mkdir(exist_ok=True)
work = Path(mkdtemp(prefix="offline-tests-", dir=root / "build"))

for suite, first_statement in [("media", "$pcma ="), ("signaling", "Coroutine::set(")]:
    source = (root / "tests" / f"{suite}.php").read_text()
    # The AOT project links src directly and invokes main as its entry point.
    loader = source.index("spl_autoload_register(")
    end = source.index("\n});", loader) + len("\n});")
    declarations, body = (source[:loader] + source[end:]).split(first_statement, 1)
    (work / "main.php").write_text(declarations + "function main(): void\n{\n" + first_statement + body + "\n}\n")
    manifest = work / "suite.yml"
    manifest.write_text(f"name: offline_{suite}\nbuild-mode: bin\nsources:\n  - main.php\n  - ../../src\n")
    binary = work / suite
    subprocess.run(["bin/tpc.php", str(manifest.relative_to(root)), "-O2",
                    "-o", "/workspace/" + str(binary.relative_to(root))], cwd=root, check=True)
    subprocess.run([binary], check=True)
