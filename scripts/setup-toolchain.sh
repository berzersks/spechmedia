#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="spechmedia-toolchain:0.10.1"

if ! command -v docker >/dev/null 2>&1; then
  printf 'Erro: Docker nao encontrado. Instale Docker e tente novamente.\n' >&2
  exit 1
fi
if ! docker info >/dev/null 2>&1; then
  printf 'Erro: daemon Docker indisponivel ou sem permissao de acesso.\n' >&2
  exit 1
fi

printf 'Preparando toolchain privada: %s\n' "$IMAGE"
docker build -f "$ROOT/Dockerfile.toolchain" -t "$IMAGE" "$ROOT"
printf 'Toolchain preparada. Compile com: bin/tpc.php hello.php\n'
