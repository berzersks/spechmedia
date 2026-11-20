#!/bin/sh
# Prepare the Git sources for both the CLI modules and TypePHP's private runtime.
set -eu
root=/opt/spechmedia/extensions
packages=""
for extension do
    case "$extension" in
        bcg729) branch=mix; packages="$packages libbcg729-dev libgsm1-dev" ;;
        psampler) branch=master ;;
        opus) branch=develop; packages="$packages libopus-dev libsoxr-dev" ;;
        *) continue ;;
    esac
    source="$root/$extension"
    if [ -d "$source" ]; then continue; fi
    git clone --depth 1 --branch "$branch" "https://github.com/berzersks/$extension.git" "$source"
    git -C "$source" rev-parse HEAD > "$source/.spechmedia-revision"
    rm -rf "$source/.git"
    docker-php-source extract
    module="/usr/src/php/ext/$extension"
    cp -a "$source" "$module"
    # These modules need phpize's config.h for the shared-module entry point.
    case "$extension" in
        bcg729|opus) sed -i '1i#include "config.h"' "$module/php_$extension.h" ;;
    esac
done

if [ -n "$packages" ]; then
    apt-get update
    apt-get install -y --no-install-recommends $packages
    rm -rf /var/lib/apt/lists/*
fi
