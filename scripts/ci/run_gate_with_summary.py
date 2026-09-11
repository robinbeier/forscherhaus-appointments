#!/usr/bin/env python3
"""Run a gate unchanged, retaining raw evidence and a shared diagnostic summary."""
import argparse
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import time
import uuid

from gate_log_summary import analyze, load_baseline

BASELINE = Path(__file__).with_name('gate_warning_baseline.json')


class GateInterrupted(BaseException):
    def __init__(self, signum):
        self.signum = signum



def write_private(path, content):
    with path.open('x', encoding='utf-8') as handle:
        os.chmod(path, 0o600)
        handle.write(content)


def render(records, status=None):
    lines = ['## Gate summary']
    failed = any(record['exit_code'] != 0 for record in records)
    outcome = 'FAIL' if failed or status in ('failure', 'cancelled') else ('PASS' if records else 'UNKNOWN')
    lines.append(f"{outcome} — {len(records)} recorded command invocation(s); unrecorded steps are not certified.")
    for record in records:
        report = record['analysis']
        lines.append(f"- {record['label']}: exit {record['exit_code']}; {record['duration_seconds']:.1f}s")
        tests = report.get('tests', [])
        lines.append('  PHPUnit invocations: ' + (json.dumps(tests, ensure_ascii=False) if tests else 'not observed'))
        lines.append('  Known warning counts: ' + json.dumps(report.get('known_warning_counts', {}), sort_keys=True))
        lines.append('  Warning delta: ' + json.dumps(report.get('warning_delta'), ensure_ascii=False))
        new = report.get('new_warning_lines', [])
        lines.append(f'  New/unclassified diagnostic lines: {len(new)} (remain visible; see raw log)')
        signals = report.get('signals', [])
        lines.append('  Coverage / architecture / deep runtime: ' + (json.dumps(signals, ensure_ascii=False) if signals else 'not observed'))
        if os.environ.get('GITHUB_REPOSITORY') and os.environ.get('GITHUB_RUN_ID'):
            artifact_url = f"https://github.com/{os.environ['GITHUB_REPOSITORY']}/actions/runs/{os.environ['GITHUB_RUN_ID']}#artifacts"
            lines.append(f"  [Raw and known-warning artifacts]({artifact_url}): {Path(record['raw_log']).name}")
        else:
            lines.append(f"  Evidence: [raw log]({Path(record['raw_log']).resolve()}); [known warnings]({Path(record['known_log']).resolve()})")
    return '\n'.join(lines) + '\n'


def finish_record(directory, stem, label, code, started, raw_path, baseline):
    report = analyze(raw_path.read_text(encoding='utf-8', errors='replace'), baseline)
    known = directory / (stem + '.known-warnings.log')
    write_private(known, report.pop('known_text'))
    report.pop('visible_text')
    record = dict(label=label, exit_code=code, duration_seconds=round(time.monotonic()-started, 3),
                  raw_log=str(raw_path), known_log=str(known), analysis=report)
    write_private(directory / (stem + '.json'), json.dumps(record, ensure_ascii=False, indent=2) + '\n')
    summary = render([record])
    write_private(directory / (stem + '.summary.md'), summary)
    print(summary, end="", flush=True)
    return code


def run(args, baseline):
    directory = Path(args.output_dir)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    stem = re.sub(r'[^a-zA-Z0-9_-]', '-', args.label) + '-' + uuid.uuid4().hex
    raw_path = directory / (stem + '.raw.log')
    started = time.monotonic()
    pending = []
    tail = []
    process = None
    interrupted = []

    def forward(signum, frame):
        interrupted.append(signum)
        # A durable marker also invalidates a record already written before cancellation.
        marker = directory / (stem + '.interrupted')
        if not marker.exists():
            write_private(marker, str(signum))
        if process is not None and process.poll() is None:
            os.killpg(process.pid, signum)
        else:
            raise GateInterrupted(signum)

    previous = {sig: signal.signal(sig, forward) for sig in (signal.SIGINT, signal.SIGTERM)}
    try:
        with raw_path.open('xb') as raw:
            os.chmod(raw_path, 0o600)
            process = subprocess.Popen(args.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, start_new_session=True)
            for data in iter(process.stdout.readline, b''):
                raw.write(data)
                raw.flush()
                line = data.decode('utf-8', errors='replace')
                tail.append(line)
                tail[:] = tail[-80:]
                if pending and (line.startswith('DEPRECATION WARNING [') or re.search(r'\b(?:WARNING|ERROR|FATAL)\b', line, re.IGNORECASE)):
                    print(''.join(pending), end='', flush=True)
                    pending.clear()
                if pending or line.startswith('DEPRECATION WARNING ['):
                    pending.append(line)
                    # Analyze only a complete Sass stack block. Exact hashes decide suppression.
                    if len(pending) > 1 and (not line.strip() and 'root stylesheet' in pending[-2]):
                        print(analyze(''.join(pending), baseline)['visible_text'], end='', flush=True)
                        pending.clear()
                    elif sum(map(len, pending)) > 65536:
                        print(''.join(pending), end='', flush=True)
                        pending.clear()
                else:
                    print(line, end='', flush=True)
            if pending:
                print(analyze(''.join(pending), baseline)['visible_text'], end='', flush=True)
            code = process.wait()
            if code < 0:
                code = 128 - code
            if interrupted:
                code = 128 + interrupted[-1]
        if code:
            print('\n[gate-summary] Failed command: immediate raw tail (full context in raw artifact).', flush=True)
            print(''.join(tail), end='', flush=True)
        return finish_record(directory, stem, args.label, code, started, raw_path, baseline)
    except GateInterrupted as error:
        print('[gate-summary] FAIL: interrupted during finalization.', file=sys.stderr)
        return 128 + error.signum
    except BaseException:
        if process is not None and process.poll() is None:
            os.killpg(process.pid, signal.SIGTERM)
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
        raise
    finally:
        for sig, handler in previous.items():
            signal.signal(sig, handler)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--label', default='gate')
    parser.add_argument('--output-dir', default='storage/logs/ci/gate-summary')
    parser.add_argument('--summarize', action='store_true')
    parser.add_argument('--job-status', choices=['success', 'failure', 'cancelled'])
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command[:1] == ['--']:
        args.command.pop(0)
    try:
        if args.summarize:
            directory = Path(args.output_dir)
            records = []
            stems = set()
            for path in sorted(directory.glob('*.json'), key=lambda item: item.stat().st_mtime_ns):
                record = json.loads(path.read_text())
                marker = path.with_suffix('.interrupted')
                if marker.exists():
                    record['exit_code'] = 128 + int(marker.read_text())
                records.append(record)
                stems.add(path.stem)
            for marker in directory.glob('*.interrupted'):
                if marker.stem not in stems:
                    records.append(dict(label='interrupted before record completion', exit_code=128 + int(marker.read_text()),
                                        duration_seconds=0, raw_log=str(marker.with_suffix('.raw.log')),
                                        known_log=str(marker), analysis={}))
            summary = render(records, args.job_status)
            print(summary, end="", flush=True)
            if os.environ.get('GITHUB_STEP_SUMMARY'):
                with open(os.environ['GITHUB_STEP_SUMMARY'], 'a', encoding='utf-8') as handle:
                    handle.write(summary)
            return 0 if records and args.job_status not in ('failure', 'cancelled') and all(record['exit_code'] == 0 for record in records) else 1
        if not args.command:
            parser.error('a command after -- is required')
        return run(args, load_baseline(BASELINE))
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f'[gate-summary] FAIL: diagnostic runner could not complete ({type(error).__name__}).', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
