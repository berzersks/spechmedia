#!/usr/bin/env bash
# Runs inside the Debian PHP image. The development headers are also needed
# later when TypePHP builds the selected modules into its own PHP runtime.
set -euo pipefail
cd /opt/spechmedia
source ./toolchain-options.sh
resolve_toolchain_options "$@"
sh ./install-audio-extensions.sh "$@"

packages=()
modules=()
audio=()
custom=()
for extension do
  case "$extension" in
    bcg729|psampler|opus) audio+=("$extension"); continue ;;
    swoole|posix) continue ;;
    swoole-hook-mysql) modules+=(pdo_mysql); continue ;;
    swoole-hook-pgsql) modules+=(pgsql); continue ;;
    swoole-hook-sqlite) packages+=(libsqlite3-dev); continue ;;
    password-argon2) packages+=(libargon2-dev); continue ;;
    mbregex) continue ;; # mbstring + oniguruma are installed in the base layer
    curl) packages+=(libcurl4-openssl-dev) ;;
    pdo_sqlite|sqlite3) packages+=(libsqlite3-dev) ;;
    readline) packages+=(libreadline-dev) ;;
    sodium) packages+=(libsodium-dev) ;;
    glfw) packages+=(libglfw3-dev libgl-dev); custom+=(glfw); continue ;;
    gmssl|swow|rar|spx|snappy|uv) custom+=("$extension"); continue ;;
  esac
  modules+=("$extension")
done

for extension in "${custom[@]}"; do
  case "$extension" in
    snappy) packages+=(libsnappy-dev) ;;
    uv) packages+=(libuv1-dev) ;;
    spx) packages+=(binutils-dev) ;;
  esac
done
if (( ${#packages[@]} )); then
  apt-get update
  apt-get install -y --no-install-recommends "${packages[@]}"
fi
if (( ${#audio[@]} )); then
  docker-php-ext-install -j"$(nproc)" "${audio[@]}"
fi

# Keep a clean copy for TypePHP's static build, separate from phpize output.
for extension in "${custom[@]}"; do
  case "$extension" in
    glfw) repo=mario-deluna/php-glfw; ref=master ;;
    gmssl) repo=gmssl/GmSSL-PHP; ref=v1.1.1
      git clone --depth 1 -b v3.2.0 https://github.com/guanzhi/GmSSL.git /tmp/gmssl
      cmake -S /tmp/gmssl -B /tmp/gmssl/build
      cmake --build /tmp/gmssl/build --parallel "$(nproc)"
      cmake --install /tmp/gmssl/build
      ldconfig
      rm -rf /tmp/gmssl ;;
    swow) repo=swow/swow; ref=v1.6.2 ;;
    rar) repo=static-php/php-rar; ref=master ;;
    spx) repo=NoiseByNorthwest/php-spx; ref=v0.4.22 ;;
    snappy) repo=kjdev/php-ext-snappy; ref=master ;;
    uv) repo=amphp/ext-uv; ref=0.3.x ;;
  esac
  git clone --recurse-submodules --shallow-submodules --depth 1 -b "$ref" "https://github.com/$repo.git" /tmp/ext-source
  revision=$(git -C /tmp/ext-source rev-parse HEAD)
  source=/tmp/ext-source
  if [[ "$extension" == swow ]]; then source+=/ext; fi
  mkdir -p extensions
  mv "$source" "extensions/$extension"
  rm -rf /tmp/ext-source "extensions/$extension/.git"
  echo "$revision" > "extensions/$extension/.spechmedia-revision"
  docker-php-source extract
  cp -a "extensions/$extension" "/usr/src/php/ext/$extension"
  if [[ "$extension" == snappy ]]; then docker-php-ext-configure snappy --with-snappy-includedir=/usr; fi
  docker-php-ext-install -j"$(nproc)" "$extension"
done

if (( ${#modules[@]} )); then
  # Use the upstream installer for bundled modules, PECL and vendor SDKs.
  installer=/tmp/install-php-extensions
  curl -fsSL --retry 3 https://raw.githubusercontent.com/mlocati/docker-php-extension-installer/2.13.2/install-php-extensions -o "$installer"
  echo "6939cd91931c898dee2f4dce92254f84f2413e8b5140cae4e4fc7a6b8ddce6a0  $installer" | sha256sum -c -
  # This is a compiler image. Keep build packages and the Oracle SDK.
  sed -i '/^cleanup$/d' "$installer"
  sh "$installer" "${modules[@]}"
  if [[ -f /etc/ld.so.conf.d/oracle-instantclient.conf ]]; then
    oracle_lib=$(cat /etc/ld.so.conf.d/oracle-instantclient.conf)
    if [[ -L "$oracle_lib/sdk" ]]; then
      cp -rL "$oracle_lib/sdk" "$oracle_lib/sdk-headers"
      rm "$oracle_lib/sdk"
      mv "$oracle_lib/sdk-headers" "$oracle_lib/sdk"
    fi
    configure_flags+=("--with-oci8=instantclient,$oracle_lib")
  fi
  rm -rf "$installer" /tmp/src
fi

printf '%s\n' "${configure_flags[@]}" > configure-options
patch -d /opt/typephp/vendor/swoole/typephp -p1 < typephp-extensions.patch
for extension in "${modules[@]}" "${audio[@]}" "${custom[@]}"; do
  php -r '$name = $argv[1] === "opcache" ? "Zend OPcache" : $argv[1]; if (!extension_loaded($name)) {fwrite(STDERR, "Missing PHP module: $name\n"); exit(1);}' "$extension"
done
docker-php-source delete
rm -rf /var/lib/apt/lists/*
