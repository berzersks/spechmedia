#!/usr/bin/env bash
# TypePHP launcher for the isolated SpechMedia toolchain. No host PHP is used.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
IMAGE="spechmedia-toolchain:0.10.1"

if (( $# == 0 )); then
  printf 'Uso: bin/tpc.php <arquivo.php|diretorio|project.yml> [opcoes TypePHP]\n' >&2
  exit 2
fi
if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
  printf 'Erro: Docker indisponivel ou sem permissao de acesso.\n' >&2
  exit 1
fi
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  printf 'Erro: toolchain nao preparada. Execute ./scripts/setup-toolchain.sh primeiro.\n' >&2
  exit 1
fi

CWD="$(pwd -P)"
case "$CWD" in
  "$ROOT") WORKDIR=/workspace ;;
  "$ROOT"/*) WORKDIR="/workspace/${CWD#"$ROOT"/}" ;;
  *)
    printf 'Erro: execute o compilador dentro do repositorio SpechMedia.\n' >&2
    exit 1
    ;;
esac

mkdir -p "$ROOT/.toolchain/home" "$ROOT/.toolchain/build"

# Options must precede '--', which delimits the compiled application's arguments.
compiler_args=()
program_args=()
after_delimiter=0
for arg in "$@"; do
  if (( ! after_delimiter )) && [[ "$arg" == '--' ]]; then
    after_delimiter=1
    continue
  fi
  if (( after_delimiter )); then
    program_args+=("$arg")
  else
    compiler_args+=("$arg")
  fi
done

# Explicit settings preserve the private PHP + Swoole runtime and writable build cache.
compiler_args+=(
  '--php-builder=extensions: [swoole, posix]; zts: off'
  '--build-dir' '/workspace/.toolchain/build'
)
if (( after_delimiter )); then
  compiler_args+=(-- "${program_args[@]}")
fi

exec docker run --rm \
  --user "$(id -u):$(id -g)" \
  --env HOME=/workspace/.toolchain/home \
  --volume "$ROOT:/workspace" \
  --workdir "$WORKDIR" \
  "$IMAGE" \
  bash -c 'set -e; python3 /opt/spechmedia/prepare-swoole-source.py; exec php /opt/typephp/vendor/bin/tpc.php "$@"' \
  spechmedia-tpc "${compiler_args[@]}"
