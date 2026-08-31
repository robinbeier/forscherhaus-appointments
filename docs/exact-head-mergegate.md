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

The command uses authenticated GitHub REST GET requests plus bounded, read-only GraphQL queries and binds all evidence to the same pull request and reviewed SHA:

1. The command must run from the reviewed branch worktree on the exact reviewed
   `HEAD`. The merge policy is loaded from the reviewed commit's own
   `.codex/contracts/agent-workflow.json`, not from mutable working-tree files.
   A mismatched local `HEAD`, missing contract blob, or local change to the
   contract or either mergegate implementation file fails closed before GitHub
   evidence is evaluated.
   The reviewed contract's workflow path and CI execution model are then
   loaded from that same commit. The canonical Agent Harness evaluators verify
   failure controls, applicability conditions, blocking-job inventory,
   execution fingerprints, and exact-execution assertions before any CI
   evidence is trusted. A changed, weakened, noop, or unconditionally skipped
   blocking job therefore fails closed; skipped evidence is accepted only
   behind this verified execution-contract invariant. Workflow YAML is parsed
   in a fresh no-ini PHP process with a package-scoped autoloader. Only the
   explicit parser runtime file manifest is loadable, and those files must
   match the aggregate SHA-256 digest pinned by the reviewed contract. Ambient
   preloaded classes, modified local vendor code, and unrelated package docs or
   dump/console helpers therefore cannot define or churn the verified CI
   execution contract.
2. The pull request is read before the first bounded evidence observation,
   between the two observations, and once more after the second observation.
   Its number, state, draft flag, base, head SHA, head branch, and head
   repository must remain identical across all three reads. The third PR read
   must be open, non-draft, target main, have the reviewed SHA as its current
   head, and report mergeable=true with a clean mergeability state.
3. Each bounded evidence observation contains the normalized commit-to-PR
   association, workflow runs, check runs, issue comments, formal reviews, and
   inline review comments for the reviewed SHA and target pull request. The two
   observations must be strictly identical. Any CI rerun, review edit, review
   comment edit, added feedback, deleted evidence, or other drift between those
   observations blocks the merge. For each non-empty REST comment page, one
   batched GraphQL node query reads only whether the creation entry is present
   and the total edit-history count. Their difference is the canonical edit
   count. Missing, partial, mismatched, duplicated, or malformed node evidence
   fails closed. This detects same-second edits and edit-then-restore changes
   without reading edit diffs or editor identities.
4. GitHub's commit-to-PR association binds that SHA to the pull request, and a
   completed pull_request run of the canonical CI workflow binds the same SHA,
   head branch, head repository, pull request number, and check suite. The
   modeled blocking checks in that suite decide success individually, so a
   workflow-level failure caused only by an advisory job does not become an
   implicit blocking gate. A workflow run without the exact PR association is
   stale evidence.
5. Every always-on blocking check exists exactly once in that suite and
   completed with success.
6. Every diff-conditional blocking check exists exactly once and completed
   with either success or skipped. Here skipped means the repository-owned CI
   condition classified it as not applicable. A required check may never use
   skipped as success.
7. One new, unedited owner-authored PR comment contains a complete, canonical
   review attestation for the reviewed SHA and the exact formal-review and
   inline-review-comment watermarks plus the privacy-safe digest of the current
   exact-SHA formal review payloads observed when it was published. The newest
   owner comment carrying the attestation marker must itself be valid; a newer
   malformed, edited, or wrong-SHA marker comment invalidates older evidence.
   Unedited means both identical creation/update timestamps and a canonical
   GraphQL edit count of zero.
8. No still-active CHANGES_REQUESTED review targets that SHA, no trusted issue
   comment is newer than the selected attestation, the current formal review
   and inline review comment maxima still equal the attested watermarks, and
   the attested formal-review payload digest still matches the current exact-SHA
   formal reviews. An inline review comment whose update timestamp is at or
   after the attestation timestamp is blocking.

Missing, pending, cancelled, neutral, failed, timed-out, stale, duplicated,
wrong-suite, wrong-SHA, edited, or malformed blocking evidence blocks the
merge. Advisory jobs remain outside this decision. The check classification is
exhaustive against ci.blocking_jobs; contract drift fails before GitHub state
is evaluated.

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
<!-- exact-head-review-attestation:v2
{"head_sha":"<40-lowercase-hex>","review_activity_watermark":{"review_id":<latest-review-id-or-0>,"review_comment_id":<latest-inline-review-comment-id-or-0>,"review_payload_digest":"<64-lowercase-hex>"},"reviews":[{"lens":"correctness_security","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"},{"lens":"design_maintainability","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"},{"lens":"tests_regression_flake","reviewer_ref":"<64-lowercase-hex>","verdict":"no_findings"}]}
-->
~~~

Each reviewer_ref is a distinct opaque digest. It must not contain a name,
login, email address, token, capability, or other personal value. Only a
comment whose GitHub author association is OWNER can be the attestation.
Extra lenses, duplicate lenses, duplicate reviewer references, non-canonical
keys, a different verdict, or a different SHA are rejected.

The numeric watermarks are the largest current IDs returned for formal reviews
and inline review comments from the contract's blocking feedback associations,
or 0 when that trusted evidence set is empty. The payload digest is the
privacy-safe hash of the normalized exact-SHA formal review payloads plus the
normalized inline review comment timestamp, body digest, and edit count from
those same associations.
Together they make later trusted activity, trusted inline-comment deletion,
and trusted formal-review body edits detectable without writing review text or
identities into the report. A higher, lower, deleted, or otherwise different
trusted maximum or payload digest invalidates the attestation. Activity from
associations outside that authority set is observed by GitHub but cannot grant
authority or veto a landing decision.

After CI and the three reviews are final, run the mergegate once before
publishing the attestation. It will still exit non-zero because the attestation
is absent, but its sanitized JSON report provides the exact
`review_activity_watermark` object without review text or actor identifiers.
Copy that object unchanged into the new attestation, publish the comment, then
rerun the full gate. Any activity between those steps makes the second run fail
closed and requires fresh evidence.

Publishing the attestation is a separate, explicit PR write performed only
after the reviews actually exist. The mergegate itself never publishes or
updates it. The attestation comment must have identical creation and update
timestamps and a zero GraphQL edit count; do not edit or reuse it. Any
correction, later trusted review activity, or later push requires a new comment
after fresh final reviews.

The gate also reads formal reviews and inline review comments from the
contract's owner/member/collaborator feedback set. A current-SHA
CHANGES_REQUESTED state from that set remains blocking until the same reviewer
has a later non-blocking review state. Any trusted review watermark drift,
trusted formal-review payload digest drift, trusted inline-review evidence
drift, or a newer owner/member/collaborator issue comment makes the attestation
stale and requires a fresh finding-free review decision plus a fresh
attestation. This closes later-feedback drift without letting an untrusted
drive-by actor veto landing and without writing reviewer identity or comment
contents to the report. A trusted inline review comment whose update timestamp
is at or after the attestation timestamp is also blocking. A same-second edit
to an older trusted issue comment is blocking as well because GitHub timestamps
have only second precision; publish a fresh attestation in a later second
instead of guessing event order.

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

Only an exit 0 after the final PR-head and bounded evidence revalidations
permits the Linear transition to Ready to Merge. The contract's compare-and-swap
merge command is the final head-SHA race boundary:

~~~bash
gh pr merge --merge --match-head-commit <current_head_sha>
~~~

GitHub offers no matching compare-and-swap primitive for arbitrary review
comments. Run the gate immediately before the merge command; feedback arriving
after its final review observation invalidates the process evidence and requires
a fresh review and gate run. Repository branch protection remains authoritative
for GitHub-native review requirements.

Afterward, verify the merge commit and origin/main before moving the issue to
Done. A successful gate is repository merge evidence; it is never deployment
or production authority.
