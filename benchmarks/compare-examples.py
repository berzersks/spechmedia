#!/usr/bin/env python3
"""Compara o uso de CPU e memória dos exemplos no Linux."""
import argparse
import json
import math
import os
import signal
import statistics
import subprocess
import tempfile
from contextlib import ExitStack, suppress
import sys
import time
import wave
from pathlib import Path


def positive_int(text):
    value = int(text)
    if value < 1:
        raise argparse.ArgumentTypeError("informe um inteiro positivo")
    return value


def snapshot(pid):
    # comm está entre parênteses e pode conter espaços ou até ')'.
    stat = Path(f"/proc/{pid}/stat").read_text().rsplit(")", 1)[1].split()
    if stat[0] in ("Z", "X"):
        raise ValueError(f"o PID {pid} já terminou")
    # Após comm, o índice zero corresponde ao campo 3 (estado).
    return time.monotonic_ns(), int(stat[19]), int(stat[11]), int(stat[12]), int(stat[21])


TICKS = os.sysconf("SC_CLK_TCK")
PAGE_MIB = os.sysconf("SC_PAGE_SIZE") / 2**20


def interval(before, after):
    if before[1] != after[1]:
        raise ValueError("o PID foi reutilizado durante a captura")
    return {
        "wall_s": (after[0] - before[0]) / 1e9,
        "user_s": (after[2] - before[2]) / TICKS,
        "system_s": (after[3] - before[3]) / TICKS,
        "rss_mib": after[4] * PAGE_MIB,
    }


def measure(args):
    previous = snapshot(args.pid)
    start, _, _, _, rss = previous
    samples = []
    print(f"Medindo {args.nome}, PID {args.pid}, durante {args.segundos} s...", file=sys.stderr)
    for second in range(1, args.segundos + 1):
        deadline = start + second * 1_000_000_000
        time.sleep(max(0, deadline - time.monotonic_ns()) / 1e9)
        now = snapshot(args.pid)
        samples.append(interval(previous, now))
        previous = now
    json.dump({"nome": args.nome, "pid": args.pid, "janela_s": args.segundos,
               "rss_inicial_mib": rss * PAGE_MIB, "amostras": samples},
              sys.stdout, indent=2, allow_nan=False)
    print()


def summarize(record):
    samples = record["amostras"]
    if not samples or ("janela_s" in record and len(samples) != record["janela_s"]):
        raise ValueError("captura incompleta")
    wall = sum(s["wall_s"] for s in samples)
    user = sum(s["user_s"] for s in samples)
    system = sum(s["system_s"] for s in samples)
    rates = sorted(100 * (s["user_s"] + s["system_s"]) / s["wall_s"] for s in samples)
    return {
        "Tempo observado (s)": wall,
        "CPU de usuário (s)": user,
        "CPU de sistema (s)": system,
        "CPU total (s)": user + system,
        "CPU média (%)": 100 * (user + system) / wall,
        "CPU mediana por intervalo (%)": statistics.median(rates),
        "CPU P95 por intervalo (%)": rates[math.ceil(0.95 * len(rates)) - 1],
        "Maior RSS amostrado (MiB)": max(record["rss_inicial_mib"], max(s["rss_mib"] for s in samples)),
    }


def audio_info(path):
    with wave.open(str(path), "rb") as audio:
        if audio.getcomptype() != "NONE" or (audio.getnchannels(), audio.getsampwidth(), audio.getframerate()) != (1, 2, 8000):
            raise ValueError(f"{path} precisa conter PCM16 mono a 8 kHz")
        frames = audio.getnframes()
        if not frames:
            raise ValueError(f"{path} está vazio")
        audio.setpos(frames - 1)
        if len(audio.readframes(1)) != 2:
            raise ValueError(f"dados PCM truncados em {path}")
        return frames / 8000


def compare(args):
    aot = json.loads(args.aot.read_text())
    pcg = json.loads(args.pcg.read_text())
    if aot["nome"] != "aot" or pcg["nome"] != "pcg":
        raise ValueError("use a captura aot seguida da captura pcg")
    if aot["janela_s"] != pcg["janela_s"]:
        raise ValueError("as capturas precisam ter a mesma duração configurada")
    left, right = summarize(aot), summarize(pcg)
    if args.wav:
        left["Áudio do WAV completo (s)"] = audio_info(args.wav[0])
        right["Áudio do WAV completo (s)"] = audio_info(args.wav[1])
    print_table(left, right)


def print_table(left, right):
    print(f"{'Métrica':32} {'SpechMedia AOT':>15} {'libspech/PCG':>15}")
    for key in dict.fromkeys((*left, *right)):
        aot = f"{left[key]:.3f}" if key in left else "—"
        pcg = f"{right[key]:.3f}" if key in right else "—"
        print(f"{key:32} {aot:>15} {pcg:>15}")
    print("\n100% de CPU corresponde a um núcleo lógico. O RSS é amostrado a cada segundo.")
    base = right["CPU média (%)"]
    if base > 0:
        delta = 100 * (left["CPU média (%)"] / base - 1)
        print(f"Variação relativa da CPU média do AOT em relação ao PCG: {delta:+.1f}%")


def current_aot(root, work):
    sources = [root / "example.php", root / "example.yml"]
    sources.extend(path for path in (root / "src").rglob("*") if path.is_file())
    newest = max(path.stat().st_mtime_ns for path in sources)
    cache = work.parent / "example"
    for binary in (root / "example", cache):
        if os.access(binary, os.X_OK) and binary.stat().st_mtime_ns >= newest:
            return binary
    print("Compilando a versão atual do SpechMedia com -O2...", flush=True)
    target = work / "example"
    subprocess.run([root / "bin/tpc.php", "example.php", "-O2", "-o",
                    "/workspace/" + str(target.relative_to(root))], cwd=root, check=True)
    target.replace(cache)
    return cache


def stop_process(process):
    if process.poll() is None:
        with suppress(ProcessLookupError):
            os.killpg(process.pid, signal.SIGTERM)
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            with suppress(ProcessLookupError):
                os.killpg(process.pid, signal.SIGKILL)
            process.wait()


def run_pair(args):
    root = Path(__file__).resolve().parents[1]
    results = root / "build/comparelibspech"
    results.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="rodada-", dir=results))
    print(f"Logs e gravações desta execução: {work}", flush=True)
    aot = current_aot(root, work)
    print("Compilando examplepcg.php com -O2...", flush=True)
    subprocess.run([root / "bin/tpc.php", "examplepcg.php", "-O2"], cwd=root, check=True)
    commands = {"aot": [aot], "pcg": [root / "examplepcg"]}
    processes = {}
    readers = {}
    previous = {}
    records = {}
    finished = set()
    with ExitStack() as stack:
        for name, command in commands.items():
            directory = work / name
            directory.mkdir()
            # O autoloader da libspech procura a configuração no diretório atual.
            if name == "pcg":
                for filename in (".env", ".env.example"):
                    if (root / filename).is_file():
                        (directory / filename).symlink_to(root / filename)
                        break
            logfile = directory / "execucao.log"
            out = stack.enter_context(logfile.open("wb"))
            readers[name] = stack.enter_context(logfile.open("rb"))
            process = subprocess.Popen(command, cwd=directory, stdout=out, stderr=subprocess.STDOUT,
                                       stdin=subprocess.DEVNULL, start_new_session=True)
            processes[name] = process
            stack.callback(stop_process, process)
        start = time.monotonic()
        for name, process in processes.items():
            previous[name] = snapshot(process.pid)
            records[name] = {"rss_inicial_mib": previous[name][4] * PAGE_MIB, "amostras": []}

        print("Janela comum desde o lançamento. 100% de CPU = um núcleo lógico.")
        print("Tempo       CPU AOT       CPU PCG       RSS AOT       RSS PCG", flush=True)
        while len(finished) < len(processes):
            time.sleep(1)
            for name, process in processes.items():
                if name in finished:
                    continue
                output = readers[name].read()
                code = process.poll()
                if code not in (None, 0) or any(msg in output for msg in (
                    b"Fatal error:", b"Erro ao registrar", b"Chamada falhou:", b"Falha na chamada:"
                )):
                    raise ValueError(f"{name} falhou. Consulte {work / name / 'execucao.log'}")
                # O callback de timer do PCG pode permanecer vivo após a gravação.
                if name == "pcg" and b"Script finalizado" in output:
                    stop_process(process)
                    finished.add(name)
                elif code == 0:
                    finished.add(name)
                if name in finished:
                    print(f"{name} finalizou.", flush=True)
            if time.monotonic() - start > args.timeout and len(finished) < len(processes):
                raise ValueError(f"prazo de {args.timeout} s excedido. Consulte os logs em {work}")
            if finished:
                continue

            try:
                current = {name: snapshot(proc.pid) for name, proc in processes.items()}
            except (FileNotFoundError, ProcessLookupError, ValueError):
                # Um dos dois pode sair entre poll() e a leitura em /proc.
                if any(proc.poll() is not None for proc in processes.values()):
                    continue
                raise
            usage = []
            memory = []
            for name in commands:
                sample = interval(previous[name], current[name])
                records[name]["amostras"].append(sample)
                usage.append(100 * (sample["user_s"] + sample["system_s"]) / sample["wall_s"])
                memory.append(sample["rss_mib"])
            previous = current
            print(f"{time.monotonic()-start:5.1f}s {usage[0]:12.1f}% {usage[1]:12.1f}%"
                  f" {memory[0]:11.1f} MiB {memory[1]:9.1f} MiB", flush=True)

    if not records["aot"]["amostras"]:
        raise ValueError("os exemplos encerraram antes de completar um intervalo comum de 1 s")
    left, right = summarize(records["aot"]), summarize(records["pcg"])
    for name, summary in (("aot", left), ("pcg", right)):
        directory = work / name
        wav = directory / "rec.wav"
        if name == "pcg" and (directory / "rec2.wav").is_file():
            wav = directory / "rec2.wav"
        if wav.is_file():
            summary["Áudio do WAV completo (s)"] = audio_info(wav)
        else:
            print(f"{name} terminou sem WAV. Confira {directory / 'execucao.log'}.")
    print("\nComparação final dos intervalos simultâneos")
    print_table(left, right)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="action", required=True)
    pair = commands.add_parser("executar", help="inicia ambos os exemplos e compara a janela comum")
    pair.add_argument("--timeout", type=positive_int, default=240, help="prazo dos exemplos em segundos")
    capture = commands.add_parser("medir", help="grava uma captura JSON na saída padrão")
    capture.add_argument("nome", choices=["aot", "pcg"])
    capture.add_argument("pid", type=positive_int, help="PID do executável ou do interpretador PHP")
    capture.add_argument("--segundos", type=positive_int, default=10)
    table = commands.add_parser("comparar", help="compara capturas da mesma fase das chamadas")
    table.add_argument("aot", type=Path)
    table.add_argument("pcg", type=Path)
    table.add_argument("--wav", nargs=2, type=Path, metavar=("WAV_AOT", "WAV_PCG"),
                       help="inclui a duração dos arquivos completos, após o encerramento")
    args = parser.parse_args()
    try:
        if args.action == "executar":
            def interrupted(signum, frame):
                raise KeyboardInterrupt
            signal.signal(signal.SIGTERM, interrupted)
            run_pair(args)
        elif args.action == "medir":
            measure(args)
        else:
            compare(args)
    except KeyboardInterrupt:
        parser.exit(130, "Medição interrompida.\n")
    except (OSError, ValueError, KeyError, EOFError, wave.Error, subprocess.CalledProcessError) as error:
        parser.exit(1, f"Falha: {error}\n")


if __name__ == "__main__":
    main()
