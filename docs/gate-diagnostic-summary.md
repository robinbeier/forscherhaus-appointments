# Gate diagnostic summaries

The full local pre-PR gate and instrumented GitHub CI jobs use
`scripts/ci/run_gate_with_summary.py` and the same log parser. Existing check
commands, conditions, timeouts and failure decisions remain authoritative.
The wrapper forwards the child exit code, including nonzero failures, and
fails if it cannot retain or summarize the evidence.

## Reading the result

At completion, `Gate summary` identifies PASS/FAIL, the observed exit code,
PHPUnit invocations and their test/assertion/skip/deprecation/notice counts,
warning baseline differences, and observed coverage, architecture and deep
runtime signals. Missing metrics are reported as not observed. Invocation
counts are not unique tests: the full gate intentionally executes overlapping
suites, and coverage shards can execute tests again.

A summary describes recorded commands only. The GitHub job status is included
in its final summary so a failed setup/action cannot become a green job merely
because another recorded command passed. The two exact deep-runtime assertion
jobs retain their existing execution contract; their underlying runtime
reports are summarized by the producer job. The change-selection job has no
gate commands to summarize.

Every command retains a unique raw log, known-warning artifact, JSON record
and Markdown summary in `storage/logs/ci/gate-summary/`. Local files are created
with private permissions. GitHub uploads the directory as `gate-summary-JOB`
for seven days and displays the same summary in the job summary panel. Original
runtime/coverage artifacts remain available. On failure, the last 80 raw lines
are immediately printed beside that command's summary; the complete raw log
retains earlier context. Cancelled jobs may stop before final artifact upload;
never treat missing records as success. An interruption marker invalidates even
a record written just before cancellation; aggregation reports unfinished
interrupted commands as failures.

## Warning baseline

Only complete Sass warning blocks matching a reviewed SHA-256 baseline exactly
are removed from the live console and copied to the known-warning artifact.
An altered source line, new warning, incomplete stack or additional diagnostic
prevents that match. All other output remains visible, including security
warnings and errors. The baseline contains hashes and warning classes, not raw
application logs or credentials.

The reference comes from the existing full-gate evidence documented in
`scripts/ci/gate_warning_baseline.json`. Counts are observations relative to
that reference, not a claim that all jobs run the same scope or that a lower
count proves a defect fixed. PHPUnit aggregate counts do not identify individual
notices; inspect the raw log and suite evidence for that. Unknown diagnostics
remain visible and are counted separately. This feature does not add suppressions
or change which warnings make the underlying tool fail.

Update the baseline only after inspecting the actual changed warnings and
reviewing the explicit baseline diff. Do not regenerate it just to hide a new
warning or make a summary look clean.

## Manual use

```bash
python3 -B scripts/ci/run_gate_with_summary.py --label example -- COMMAND ARGUMENTS
python3 -B scripts/ci/run_gate_with_summary.py --summarize --output-dir PATH_TO_THIS_RUN
```

Choose a directory containing only the intended run's records for aggregation.
The normal local Full Gate automatically wraps its own invocation and reports
only that invocation. Raw logs may contain whatever the underlying tools print;
keep synthetic fixtures and existing evidence-privacy rules in place. Do not
publish local logs containing secrets or personal data.
