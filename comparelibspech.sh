#!/usr/bin/env bash
# Compara as linhas CPU Usage impressas por example e examplepcg.
set -euo pipefail
cd -- "$(dirname -- "$0")"
exec python3 benchmarks/compare-examples.py executar "$@"
