#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

"$ROOT/bin/tpc.php" hello.php -o bin/hello

if [[ ! -x "$ROOT/bin/hello" ]]; then
  printf 'Erro: compilacao nao produziu bin/hello.\n' >&2
  exit 1
fi
printf 'Binario criado: %s/bin/hello\n' "$ROOT"
