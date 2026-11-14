#!/usr/bin/env python3
"""Soak test report: resource trends from samples.csv plus data consistency
checks against PostgreSQL and the mock provider's counters.

    python3 tests/soak/report.py <output dir of tests/soak/run.sh>

Exits non-zero when a check fails.
"""

import csv
import json
import re
import subprocess
import sys
from pathlib import Path


def psql(sql: str) -> str:
    return subprocess.run(
        ["docker-compose", "exec", "-T", "postgres", "psql", "-U", "postgres", "-d", "notifications", "-tAc", sql],
        capture_output=True, text=True, check=True,
    ).stdout.strip()


def container_mem_mb(field: str) -> float:
    """'123.4MiB / 7.7GiB' -> 123.4"""
    value = field.split("/")[0].strip()
    for unit, factor in (("GiB", 1024), ("MiB", 1), ("KiB", 1 / 1024), ("B", 1 / 1048576)):
        if value.endswith(unit):
            return float(value[: -len(unit)]) * factor
    return 0.0


def containers(row: dict) -> dict:
    """'horizon=123.4MiB / 7.7GiB=12.5% app=…' -> {'horizon': (123.4, 12.5), …}"""
    result = {}
    for name, mem, cpu in re.findall(r"([\w-]+)=([\d.]+[KMG]?i?B) / [\d.]+[KMG]?i?B=([\d.]+)%", row["containers"]):
        result[name] = (container_mem_mb(mem), float(cpu))
    return result


def main() -> int:
    out = Path(sys.argv[1])
    started_at = (out / "started_at").read_text().strip()
    rows = list(csv.DictReader((out / "samples.csv").open()))
    # The container column holds spaces, so DictReader splits it: re-join the overflow.
    for row in rows:
        extra = row.pop(None, None)
        if extra:
            row["containers"] = " ".join([row["containers"], *extra])

    load = [r for r in rows if r["phase"] == "load"]
    idle = [r for r in rows if r["phase"] == "idle"]
    checks: list[tuple[bool, str]] = []

    print(f"Soak report for {out} (started {started_at}, {len(rows)} samples)\n")

    # --- Resource trend: compare the second sample (warm) with the last load sample.
    print("Resources (MB)            first     last      max")
    series = {"redis used": [int(r["redis_mb"] or 0) for r in rows]}
    for name in ("horizon", "app", "redis", "postgres", "jaeger", "mock-provider", "scheduler"):
        series[f"container {name}"] = [containers(r).get(name, (0, 0))[0] for r in rows]

    # Per-process memory from /proc (process-rss.csv): the newest sample per pid.
    worker_max = 0
    rss_file = out / "process-rss.csv"
    if rss_file.exists():
        by_epoch: dict[int, list[tuple[str, int]]] = {}
        for rec in csv.DictReader(rss_file.open()):
            if rec["rss_kb"]:
                by_epoch.setdefault(int(rec["epoch"]), []).append((rec["cmd"], int(rec["rss_kb"]) // 1024))
        epochs = sorted(by_epoch)
        def per_kind(match: str) -> list[int]:
            return [max((mb for cmd, mb in by_epoch[e] if match in cmd), default=0) for e in epochs]
        series["horizon:work max RSS"] = per_kind("horizon:work")
        series["php-fpm worker max RSS"] = per_kind("php-fpm: pool")
        series["schedule:work RSS"] = per_kind("schedule:work")
        worker_max = max(series["horizon:work max RSS"] or [0])

    for name, values in series.items():
        values = [v for v in values if v]
        if values:
            print(f"  {name:<24} {values[0]:>8.0f} {values[-1]:>8.0f} {max(values):>8.0f}")

    checks.append((0 < worker_max < 128, f"no worker above Horizon's 128 MB limit (max {worker_max} MB)"))
    sched = [v for v in series.get("schedule:work RSS", []) if v]
    if len(sched) > 4:
        checks.append((sched[-1] - sched[1] < 16, f"scheduler process memory flat ({sched[1]} -> {sched[-1]} MB)"))

    redis_keys = [int(r["redis_keys"] or 0) for r in rows]
    print(f"\n  redis keys: first {redis_keys[0]}, max {max(redis_keys)}, last {redis_keys[-1]}")
    pg = [int(r["pg_conn"] or 0) for r in rows]
    checks.append((max(pg) < 90, f"PostgreSQL connections stay well below max_connections (max {max(pg)})"))

    # --- Idle CPU: nothing left spinning once the work is done.
    if idle:
        idle_cpu = {name: max(containers(r).get(name, (0, 0))[1] for r in idle) for name in ("horizon", "app", "scheduler", "redis", "postgres")}
        print("\nIdle CPU (max % over the idle samples): " + ", ".join(f"{k} {v:.1f}" for k, v in idle_cpu.items()))
        checks.append((idle_cpu["horizon"] < 10, f"Horizon idles (max {idle_cpu['horizon']:.1f}% CPU)"))
        checks.append((idle_cpu["app"] < 5, f"API idles (max {idle_cpu['app']:.1f}% CPU)"))

    # --- Data consistency for everything created during the run.
    since = f"created_at >= '{started_at}'"
    counts = dict(
        line.split("|") for line in psql(f"select status, count(*) from notifications where {since} group by status").splitlines() if line
    )
    total = sum(int(v) for v in counts.values())
    print(f"\nNotifications created during the run: {total} -> {counts}")
    in_flight = sum(int(counts.get(s, 0)) for s in ("pending", "queued", "processing", "retrying"))
    checks.append((in_flight == 0, f"nothing stuck in pending/queued/processing/retrying ({in_flight})"))
    checks.append((total > 0 and int(counts.get("sent", 0)) / total > 0.97, f"at least 97% sent ({int(counts.get('sent', 0)) / max(total, 1):.2%})"))

    attempts_col = int(psql(f"select coalesce(sum(attempts), 0) from notifications where {since}"))
    attempts_rows = int(psql(f"select count(*) from notification_attempts a join notifications n on n.id = a.notification_id where n.{since}"))
    checks.append((attempts_col == attempts_rows, f"attempt counters match the attempt history ({attempts_col} vs {attempts_rows})"))

    double_success = int(psql(
        "select count(*) from (select a.notification_id from notification_attempts a join notifications n on n.id = a.notification_id "
        f"where n.{since} and a.outcome = 'success' group by a.notification_id having count(*) > 1) x"
    ))
    checks.append((double_success == 0, f"no notification recorded as accepted twice ({double_success})"))

    sent_without_id = int(psql(f"select count(*) from notifications where {since} and status = 'sent' and provider_message_id is null"))
    checks.append((sent_without_id == 0, f"every sent notification has a provider messageId ({sent_without_id} without)"))

    # --- Provider side: duplicates = the same notification accepted twice.
    stats = json.loads((out / "provider-stats.json").read_text())["channels"]
    accepted = sum(c["accepted"] for c in stats.values())
    duplicates = sum(c.get("duplicates", 0) for c in stats.values())
    print(f"Provider: {sum(c['requests'] for c in stats.values())} requests, {accepted} accepted, {duplicates} duplicate deliveries, "
          f"peak/s {max(c['maxPerSecond'] for c in stats.values())}")
    checks.append((accepted - duplicates == int(counts.get("sent", 0)),
                   f"provider accepted each sent notification exactly once ({accepted - duplicates} unique vs {counts.get('sent', 0)} sent)"))

    print("\nEvents:")
    print((out / "events.log").read_text())

    print("Checks:")
    for ok, text in checks:
        print(f"  {'PASS' if ok else 'FAIL'}  {text}")

    return 0 if all(ok for ok, _ in checks) else 1


if __name__ == "__main__":
    sys.exit(main())
