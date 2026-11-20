#!/usr/bin/env bash
set -euo pipefail
binary=${1:?pass benchmark binary}
echo 'mode,pt,ptime_ms,calls,packets,pps,mean_ns,batch_p50_ns,batch_p95_ns,cpu_ns_per_packet,peak_rss_kib,idle_rss_bytes_per_session,missing,cpu_us,wall_ns'
for mode in decode parse pipeline udp; do
    for pt in 8 0; do
        for ptime in 10 20 60; do
            calls=(1 10 100 500 1000)
            if [[ $mode == decode || $mode == parse ]]; then calls=(1); fi
            for count in "${calls[@]}"; do
                if ! output=$(MODE=$mode PT=$pt PTIME=$ptime CALLS=$count "$binary"); then
                    echo "$output" >&2
                    exit 1
                fi
                row=$(grep "^$mode," <<< "$output")
                echo "$row"
            done
        done
    done
done
