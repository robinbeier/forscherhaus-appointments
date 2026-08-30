# Exact-Head Mergegate

Purpose: provide one repository-owned, read-only decision immediately before a
pull request enters Ready to Merge.

The gate reads GitHub state, evaluates the canonical contract in
.codex/contracts/agent-workflow.json, writes a sanitized local JSON report,
and exits non-zero unless every landing invariant is satisfied. It does not
approve, comment on, label, merge, close, or otherwise mutate a pull request.

## Inputs

Run the gate from the reviewed branch worktree:

~~~bash
composer check:exact-head-mergegate -- --pr=<number-or-canonical-url> --reviewed-sha=<40-character-sha>
~~~

Only two authority-bearing inputs exist:

- the pull request number or its canonical
  https://github.com/<owner>/<repo>/pull/<number> URL
- the complete commit SHA that received the final reviews

The repository identity is derived from the canonical GitHub origin remote.
When GITHUB_REPOSITORY is present, it must match that origin exactly. A foreign
host, repository mismatch, abbreviated SHA, ambiguous target, missing input,
or duplicate input fails closed.

## Evidence Contract

The command uses authenticated GitHub GET requests and binds all evidence to
the same pull request and reviewed SHA:

1. The pull request is open, non-draft, targets main, has the reviewed SHA as
   its current head, and GitHub reports mergeable=true with a clean
   mergeability state.
2. GitHub's commit-to-PR association binds that SHA to the pull request, and a
   completed successful pull_request run of the canonical CI workflow binds
   the same SHA, head branch, head repository, pull request number, and check
   suite. A workflow run without that exact PR association is stale evidence.
3. Every always-on blocking check exists exactly once in that suite and
   completed with success.
4. Every diff-conditional blocking check exists exactly once and completed
   with either success or skipped. Here skipped means the repository-owned CI
   condition classified it as not applicable. A required check may never use
   skipped as success.
5. One owner-authored PR comment contains a complete, canonical review
   attestation for the reviewed SHA.
6. No still-active CHANGES_REQUESTED review targets that SHA, no trusted issue
   comment is newer than the selected attestation, and no formal review or
   inline review comment is as new as or newer than it.

Missing, pending, cancelled, neutral, failed, timed-out, stale, duplicated,
wrong-suite, wrong-SHA, or malformed evidence blocks the merge. Advisory jobs
remain outside this decision. The check classification is exhaustive against
ci.blocking_jobs; contract drift fails before GitHub state is evaluated.

## Review Attestation

The three final reviews remain independent review work. The attestation is the
repository's accountable operator assertion that those reviews actually ran
and are finding-free; its opaque references are not cryptographic proof of an
agent execution. This is an explicit trust boundary: the gate prevents stale,
incomplete, or accidentally mismatched landing evidence, but it does not try
to defend against a malicious repository owner who could already bypass this
repository-local process and merge directly. A false attestation is a process
violation, not evidence that the gate can authenticate independently. After
the reviews complete, the primary agent records their result in one PR comment
with this exact shape:

~~~text
<!-- exact-head-review-attestation:v1
{"head_sha":"<40-lowercase-hex>","reviews":[{"lens":"correctness_security","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"},{"lens":"design_maintainability","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"},{"lens":"tests_regression_flake","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"}]}
-->
~~~

Each reviewer_ref is a distinct opaque digest. It must not contain a name,
login, email address, token, capability, or other personal value. Only a
comment whose GitHub author association is OWNER can be the attestation.
Extra lenses, duplicate lenses, duplicate reviewer references, non-canonical
keys, a different verdict, or a different SHA are rejected.

Publishing the attestation is a separate, explicit PR write performed only
after the reviews actually exist. The mergegate itself never publishes or
updates it. Any later push makes the attestation stale by construction and
requires new final reviews plus a new exact-head attestation.

The gate also reads formal reviews and inline review comments. A current-SHA
CHANGES_REQUESTED state remains blocking until the same reviewer has a later
non-blocking review state. Any newer formal or inline review activity, or a
newer owner/member/collaborator issue comment, makes the attestation stale and
requires a fresh finding-free review decision plus a fresh attestation. This
closes later-feedback drift without writing reviewer identity or comment
contents to the report.

## Result and Landing

The default report is:

~~~text
storage/logs/ci/exact-head-mergegate-latest.json
~~~

It contains only the repository, pull request number, reviewed SHA, selected
run/suite identifiers, and sanitized gate outcomes. Raw GitHub payloads,
comment bodies, reviewer references, credentials, tokens, and API error bodies
are never written to the report.

Exit codes:

- 0: every exact-head invariant passed
- 1: GitHub state was read successfully but the pull request is not ready
- 2: input, contract, API, pagination, or report publication failed

Only an exit 0 on the still-current SHA permits the Linear transition to Ready
to Merge. Land with the contract's compare-and-swap command:

~~~bash
gh pr merge --merge --match-head-commit <current_head_sha>
~~~

Afterward, verify the merge commit and origin/main before moving the issue to
Done. A successful gate is repository merge evidence; it is never deployment
or production authority.
