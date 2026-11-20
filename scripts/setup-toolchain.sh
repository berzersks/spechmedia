#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE="spechmedia-toolchain:0.10.1"

# Keep the CLI catalog aligned with the groups on setup.html.
supported=(
  swoole posix
  bcg729 psampler opus gd glfw imagick
  swoole-hook-mysql swoole-hook-pgsql swoole-hook-sqlite swow parallel pcntl sockets sysvmsg sysvsem sysvshm shmop event inotify uv dio ffi ftp
  dba ldap mongodb mysqli mysqlnd oci8 odbc pdo pdo_mysql pdo_odbc pdo_pgsql pdo_sqlite pdo_sqlsrv pgsql sqlite3 sqlsrv
  amqp apcu igbinary memcache memcached msgpack rdkafka redis yac
  ctype dom enchant exif fileinfo gettext iconv intl libxml mbregex mbstring simdjson simplexml soap tidy tokenizer xlswriter xml xmlreader xmlwriter xsl yaml
  bz2 phar rar snappy zip zlib zstd
  curl gmssl grpc imap mcrypt openssl password-argon2 protobuf sodium ssh2
  ast bcmath calendar ds filter gmp opcache opentelemetry readline session spx uuid xdebug xhprof
)
extensions=()
for extension in "$@"; do
  if [[ ! "$extension" =~ ^[a-z0-9_-]+$ || " ${supported[*]} " != *" $extension "* ]]; then
    printf 'Extensão desconhecida: %s\n' "$extension" >&2
    exit 2
  fi
  if [[ " ${extensions[*]} " != *" $extension "* ]]; then extensions+=("$extension"); fi
done

source "$ROOT/scripts/toolchain-options.sh"
resolve_toolchain_options "${extensions[@]}"
php_variant=cli
if [[ "$zts" == on ]]; then php_variant=zts; fi

if ! command -v docker >/dev/null 2>&1; then
  printf 'Erro: Docker nao encontrado. Instale Docker e tente novamente.\n' >&2
  exit 1
fi
if ! docker info >/dev/null 2>&1; then
  printf 'Erro: daemon Docker indisponivel ou sem permissao de acesso.\n' >&2
  exit 1
fi

printf 'Preparando toolchain privada: %s\n' "$IMAGE"
docker build --build-arg "PHP_VARIANT=$php_variant" --build-arg "EXTRA_EXTENSIONS=${extensions[*]}" -f "$ROOT/Dockerfile.toolchain" -t "$IMAGE" "$ROOT"
printf 'Toolchain preparada. Compile com: bin/tpc.php hello.php\n'
