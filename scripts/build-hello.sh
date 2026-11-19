#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="spechmedia-toolchain:0.10.1"

if ! command -v docker >/dev/null 2>&1; then
  printf 'Erro: Docker nao encontrado.\n' >&2
  exit 1
fi
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  printf 'Erro: toolchain nao preparada. Execute ./scripts/setup-toolchain.sh primeiro.\n' >&2
  exit 1
fi

mkdir -p "$ROOT/.toolchain/home" "$ROOT/bin"

printf 'Compilando Hello World via TypePHP AOT...\n'
docker run --rm \
  --user "$(id -u):$(id -g)" \
  --env HOME=/workspace/.toolchain/home \
  --volume "$ROOT:/workspace" \
  --workdir /workspace \
  "$IMAGE" \
  php /opt/typephp/vendor/bin/tpc.php examples/hello.php --php-builder -o bin/hello

if [[ ! -f "$ROOT/bin/hello" ]]; then
  printf 'Erro: compilacao nao produziu bin/hello.\n' >&2
  exit 1
fi

printf 'Binario criado: %s/bin/hello\n' "$ROOT"
