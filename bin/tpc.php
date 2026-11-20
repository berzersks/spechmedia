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
if ! extras=$(docker image inspect --format '{{with .Config.Labels}}{{index . "spechmedia.extensions"}}{{end}}' "$IMAGE" 2>/dev/null); then
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
# The SIP example is a multi-source TypePHP project. Preserve all user flags.
if [[ -f "$1" && "$1" -ef "$ROOT/example.php" ]]; then
  shift
  set -- /workspace/example.yml "$@"
fi

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
source "$ROOT/scripts/toolchain-options.sh"
read -ra selected <<< "$extras"
resolve_toolchain_options "${selected[@]}"
extensions=$(IFS=,; echo "${runtime[*]}")
compiler_args+=(
  "--php-builder=extensions: [$extensions]; zts: $zts"
  '--build-dir' '/workspace/.toolchain/build'
)
if (( after_delimiter )); then
  compiler_args+=(-- "${program_args[@]}")
fi

exec docker run --rm \
  --user "$(id -u):$(id -g)" \
  --env "SPECHMEDIA_EXTENSIONS=$extras" \
  --env HOME=/workspace/.toolchain/home \
  --volume "$ROOT:/workspace" \
  --workdir "$WORKDIR" \
  "$IMAGE" \
  bash -c 'set -e
    if [[ " $SPECHMEDIA_EXTENSIONS " != *" swow "* ]]; then
      python3 /opt/spechmedia/prepare-swoole-source.py
    fi
    exec php /opt/typephp/vendor/bin/tpc.php "$@"' \
  spechmedia-tpc "${compiler_args[@]}"
