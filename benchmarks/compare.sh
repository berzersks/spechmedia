#!/usr/bin/env bash
# Run from the repository root. CPU=<logical CPU> optionally sets affinity.
set -euo pipefail
root=$(git rev-parse --show-toplevel)
cd "$root"
mkdir -p build
work=$(mktemp -d build/rtp-bench.XXXXXX)
mkdir -p "$work/ref/benchmarks"
git archive 376acdc src | tar -x -C "$work/ref"
cp benchmarks/media.yml "$work/ref/benchmarks"
# Baseline uses its original parse + receive API on exactly the same datagrams.
sed 's/receiveDatagram($wire)/receive(RtpPacket::parse($wire, 8000))/' \
    benchmarks/media.php >"$work/ref/benchmarks/media.php"
runner=()
if [[ -n ${CPU:-} ]]; then runner=(taskset -c "$CPU"); fi
for opt in 2 3; do
    for version in ref opt; do
        project=benchmarks/media.yml
        if [[ $version == ref ]]; then project="$work/ref/$project"; fi
        binary="$work/$version$opt"
        bin/tpc.php "$project" "-O$opt" -o "/workspace/$binary"
        "${runner[@]}" benchmarks/run.sh "$binary" >"$binary.csv"
    done
done
# Repeat a fixed 20 ms workload, rotating the four binaries across trials.
report="$work/results.csv"
echo "trial,version,optimization,$(head -1 "$work/ref2.csv")" >"$report"
binaries=(ref2 opt2 opt3 ref3)
for name in "${binaries[@]}"; do
    tail -n +2 "$work/$name.csv" | sed "s/^/0,${name%?},${name: -1},/" >>"$report"
done
for trial in 1 2 3 4 5; do
    for offset in 0 1 2 3; do
        name=${binaries[$(((trial - 1 + offset) % 4))]}
        for pt in 0 8; do
            for mode in decode parse pipeline udp; do
                calls=1
                if [[ $mode == pipeline || $mode == udp ]]; then calls=1000; fi
                row=$(MODE=$mode PT=$pt PTIME=20 CALLS=$calls "${runner[@]}" "$work/$name")
                echo "$trial,${name%?},${name: -1},$row" >>"$report"
            done
        done
    done
done
echo "Results: $root/$report"
