# Optional agent review tooling

The normal review and landing path is defined in [WORKFLOW.md](../WORKFLOW.md).
It does not require these tools. Use this legacy path only when the user
explicitly requests it; unavailable CLI authentication, platform support, or
bootstrap diagnostics are not prerequisites for a standard review.

The sections below retain the existing specialized tooling contracts. Their
mandatory wording applies only inside this opt-in path. The sealed runner,
its runtime pins, and its fail-closed checks are unchanged. It is not a
fallback to invoke these tools directly from an unverified checkout.
The machine settings in `authority.reviewer`, `trusted_base_bootstrap`, and
`land.exact_head_mergegate` describe this optional subsystem; `review` and the
other `land` fields describe the normal path.

For controlled parallel implementation, use the ownership guidance in `WORKFLOW.md`.
This is independent of whether the final review uses the standard path.
Relative repository paths in the command and contract text are repository-root
paths. Review-tool implementation changes can receive a standard independent
review; producing sealed-tool evidence still requires its bootstrap boundary.

## Trusted reviewer bootstrap

This optional reviewer uses the exact-base launcher and verified payload
materialization described below. Do not replace this with an ambient `git show`
or a checked-out wrapper. Normal implementation delegation and its
ownership limits remain in [WORKFLOW.md](../WORKFLOW.md).

The canonical Primary-owned invocation shape is below. Supply an absolute
repository root, its verified 40-character base, the `reviewer` payload name,
and only that payload's documented arguments. The outer command is static host
code: complete materialization and blob verification must succeed before any
repository-selected Bash can run.

```bash
/usr/bin/env -i PATH=/usr/bin:/bin:/usr/sbin:/sbin /bin/bash --noprofile --norc -c '
  set -euo pipefail
  repo_root="$1"
  base_sha="$2"
  payload="$3"
  shift 3
  [[ "$repo_root" == /* && "$base_sha" =~ ^[a-f0-9]{40}$ ]]
  case "$(/usr/bin/uname -s)" in
    Darwin) private_parent=/private/tmp ;;
    Linux) private_parent=/tmp ;;
    *) exit 2 ;;
  esac
  umask 077
  bootstrap_root="$(/usr/bin/mktemp -d "$private_parent/forscherhaus-trusted-launcher.XXXXXX")"
  case "$bootstrap_root" in "$private_parent"/forscherhaus-trusted-launcher.*) ;; *) exit 2 ;; esac
  cleanup() { /bin/chmod -R u+w -- "$bootstrap_root" 2>/dev/null || true; /bin/rm -rf -- "$bootstrap_root"; }
  trap cleanup EXIT
  git_read() {
    /usr/bin/env -i GIT_ATTR_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_SYSTEM=/dev/null GIT_NO_LAZY_FETCH=1 GIT_NO_REPLACE_OBJECTS=1 GIT_OPTIONAL_LOCKS=0 GIT_PAGER=cat GIT_TERMINAL_PROMPT=0 LANG=C LC_ALL=C PATH=/usr/bin:/bin:/usr/sbin:/sbin /usr/bin/git -c core.hooksPath=/dev/null -C "$repo_root" "$@"
  }
  launcher_path="$bootstrap_root/trusted_base_launcher.sh"
  launcher_entry="$(git_read ls-tree "$base_sha" -- scripts/agent/trusted_base_launcher.sh)"
  read -r launcher_mode launcher_type launcher_blob launcher_tree_path <<< "$launcher_entry"
  [[ "$launcher_mode" == 100644 && "$launcher_type" == blob && "$launcher_blob" =~ ^[a-f0-9]{40}$ && "$launcher_tree_path" == scripts/agent/trusted_base_launcher.sh ]]
  git_read show "$base_sha:scripts/agent/trusted_base_launcher.sh" > "$launcher_path"
  [[ "$(git_read hash-object --no-filters "$launcher_path")" == "$launcher_blob" ]]
  /bin/chmod 0500 "$launcher_path"
  /usr/bin/env -i PATH=/usr/bin:/bin:/usr/sbin:/sbin TMPDIR=/tmp LANG=C LC_ALL=C TRUSTED_BASE_MATERIALIZED=1 TRUSTED_BASE_LAUNCHER_SOURCE_PATH="$launcher_path" /bin/bash --noprofile --norc "$launcher_path" --repo-root="$repo_root" --base-sha="$base_sha" --payload="$payload" -- "$@"
' trusted-base-launcher /absolute/repository <base-sha> reviewer <payload-options>
```

The reviewer entry point begins with the same exact-base system-Git
launcher, discards caller startup configuration, and uses isolated
`/usr/bin/python3` before any PHP runs.
The launcher first materializes the fixed bootstrap-contract parser as an
exact regular blob from that base. Launcher and shared runtime invoke that same
parser at separate attestation points, so manifest, mode, payload, and runtime
bindings are checked twice. The runtime also retains a deliberately independent
structural cross-check; this small security-floor redundancy may not be removed
as ordinary cleanup.
`scripts/agent/verify_trusted_php_runtime.py` owns contract policy and CLI
dispatch; `scripts/agent/lib/trusted_runtime_primitives.py` owns the separately
testable file, archive, ELF, and dependency-closure mechanics. Together they
bind PHP and its dynamic dependency closure to the exact-base machine contract.
Every admitted platform requires an exact closure pin; a missing platform pin
or any pin drift fails closed and requires a reviewed contract update. A
fixed-host-path closure must also be entirely system-owned. Alternatively, a
platform may use one HTTPS archive whose URL, archive digest, sole member,
member digest, private extraction mode, static non-system closure, and aggregate
closure are all exact-base-bound. Both macOS runtimes use that bounded archive
path; they are downloaded without ambient proxy or credential state, verified
before extraction, and never executed from the archive. Platforms absent from
both runtime-source maps and the closure-pin map are deliberately unsupported.
Ambient `PATH` never grants interpreter authority.

The exact-base JSON contract is the sole declarative configuration authority
for reviewer profiles, runtime pins, disabled features, and trusted paths. PHP
requires both the deterministic committed snapshot and the complete code-side
policy attestation to match that policy exactly. Run
`php scripts/agent/generate_reviewer_policy_snapshot.php` only for the snapshot
and `php scripts/agent/generate_reviewer_runtime_attestation.php` only for the
separate `GeneratedReviewerRuntimeAttestation.php` artifact containing every
top-level reviewer-policy key and its independent digest. Neither generator
rewrites runtime enforcement code. Each generator has a side-effect-free
`--check` mode and owns only its named artifact. Explicit disabled-feature
floors remain hand-enforced. Both generators and both generated artifacts are
trusted bootstrap paths, so changing either generation contract also requires
the external bootstrap-review path.

These repeated checks are distinct trust anchors, not competing policy
implementations. The launcher proves the exact-base materialization contract
before repository code runs; the shared runtime reattests that same contract
after dispatch; the generated snapshot makes the declarative policy reviewable;
and the complete PHP attestation keeps an independently changed JSON file from
redirecting enforcement. For the same reason, the canonical remote remains a
literal fail-closed transport/identity floor in each external-boundary payload
instead of being read only from mutable policy JSON. Changes to one of these
anchors must update its exact-base peers and tests together. Do not centralize
them into a head helper or one policy-derived lookup: a partial update is meant
to fail closed.

External review input is deliberately narrower than a checkout. It contains a
zero-context UTF-8 patch (changed lines only), the normalized changed-path
index, its deterministic manifest, and the allowlisted trusted base policy.
Full base/head file blobs and unchanged hunk context are never materialized or
serialized; unchanged hunk-section headings are stripped as well. Binary diffs
stop before any model request because they cannot be reviewed without
transmitting broader blob content. The serializer rejects every file outside
its exact manifest-derived allowlist. Tracked symbolic links and gitlinks are
rejected before bundle materialization because their target content is not part
of that exact text-only evidence boundary. `AGENTS.md`, `WORKFLOW.md`, and
`code_review.md` are trusted policy context, so changing any of them requires
the external bootstrap-review path.

Exactly one primary remains the external single writer for commits, pushes,
PRs, checks, Linear, workpads, attestations, merges, and production actions.
Workers may edit only their assigned local ownership. Shared contracts,
cross-lane integration files, and landing helpers remain primary-owned. Stop a
lane if it needs another lane's files, ownership becomes ambiguous, or a
semantic cross-lane dependency appears.

A merge invalidates the base of every remaining lane. Before any remaining
lane can publish, the primary synchronizes it with the newly verified
`origin/main`, resolves integration centrally, and reruns all validation and
exact-head review evidence invalidated by that synchronization.

## PR and Review Expectations

Every PR must cover at least two independent review lenses:

- Reviewer A: bugs, regressions, security, edge cases
- Reviewer B: architecture, readability, maintainability

Authority-, secret-, identity-, transaction-, and concurrency-sensitive
changes require a third independent lens:

- Reviewer C: tests, regression coverage, and flake risk

Final reviews and blocking CI must all target the current unchanged exact PR
head. A later push makes the earlier evidence stale.

Run final reviewers through the repository-owned sealed-bundle boundary using
the exact-base launcher contract in `scripts/agent/trusted_base_launcher.sh`;
never execute the checked-out launcher or reviewer payload. Fixed system Git
must completely materialize and verify the launcher from the verified base
before clean Bash starts it; only then may it privately materialize the fixed
`scripts/agent/lib/trusted_base_bootstrap_contract.py` parser, validate the
manifest, and materialize `scripts/agent/lib/trusted_base_payload_runtime.sh` and
`scripts/agent/run_readonly_reviewer.sh`.
The shared runtime, runner, policy, profiles, schema,
and validator are trusted base artifacts, never head artifacts. It requires
the live canonical main, local tracking ref, exact merge base, and reviewed
head to match; later pushes invalidate all review evidence.

The harness enforces the deterministic sealed bundle, exact Base/Head binding,
macOS Seatbelt default-deny isolation, an attested PHP runtime, and a private
Codex copy whose exact binary and system-only dynamic dependency closure are
pinned before its first execution. The sandbox has no broad Homebrew-library
allowance. It also enforces disabled reviewer tools,
and privacy-safe fail-closed output. It exposes no worktree or `.git`, user
configuration, connectors, delegation, credentials, or external writes;
non-macOS execution fails closed. The machine contract is the source for model,
feature, schema, runtime, and trusted-path settings; the runner orchestrates
separately materialized exact-base bundle and isolated-runtime libraries.
Commit-derived evidence is rendered through an empty-template private Gitdir.
Its index is first bound to the verified review base so `check-attr --cached`
can reject paths marked binary by trusted-base attributes. Raw blobs from both
commits must also be bounded UTF-8 without NUL bytes before the index advances
to the verified head and zero-context numstat and patch evidence is rendered.
Independent numeric-numstat validation rejects any remaining binary
classification before model input.
Head-side attribute changes remain untrusted diff content and cannot
reclassify or conceal binary evidence before rejection. Source-worktree Git
configuration, `.git/info/attributes`, and host Git templates cannot influence
changed paths, attribute evidence, numstat, or patch bytes.
The pinned CLI exposes `--ignore-user-config`, `--ignore-rules`, and
`--strict-config` on `exec` but rejects them on its `debug` preflights. Those
preflights therefore use `env -i`, a newly created non-writable synthetic
`HOME`/`CODEX_HOME` containing no config or rules, a sealed working directory,
and the same Seatbelt boundary; the final `exec` requires all three flags.
The version-pinned model-catalog adapter drops unknown catalog additions
without failing, but rejects missing or type-drifted fields needed by that
exact CLI ABI. Capability-bearing fields are always reconstructed to the
disabled reviewer surface; the required web-search representation enum is
pinned to its smallest text-only form while search support stays disabled.
The machine contract also owns the exact recursive Git pathspec that sends any
nested `AGENTS.md` change back through bootstrap review; the shell runner does
not add an implicit policy glob.
Bundle construction, model/prompt policy, and output validation remain separate
modules. Structural output rules come from the exact-base JSON schema; exact
Base/Head/lens/path binding and privacy are additional semantic checks.
The JSON machine contract is the only hand-edited reviewer-policy authority.
Generated PHP policy and runtime-attestation files are deterministic
change-control projections refreshed by their repository generators, not
additional policy sources. Exact equality is intentional: a runtime-boundary
change must be explicit and generator-checked.
Consult `.codex/contracts/agent-workflow.json`,
`scripts/agent/trusted_base_launcher.sh`, and the reviewer payload for those
implementation details.

The first introduction of this trust root cannot bootstrap itself. Likewise,
a change to `.codex/config.toml`, any `AGENTS.md`, or any reviewer bootstrap,
role, schema, isolation, runtime, or policy-context path declared by the exact
base contract can affect future review authority. Those changes fail closed and
need a separately enforced external read-only bootstrap review authorized and
run by the primary. The contract owns those path lists; shell and tests consume
them instead of maintaining additional allowlist copies. The isolated model call
uses both the outer Seatbelt boundary and Codex `read-only` sandboxing with
approval mode `never`. A bootstrap review is review evidence only; it grants no
mutation, publication, Linear, or landing authority.

The Seatbelt network allowance exists only for the Primary-authorized outer
Codex model transport. The model receives no network tool, connector,
endpoint override, ambient proxy setting, or external credential with which to
select another destination. The local Primary account, the attested Codex
binary, and the operating-system sandbox are trust anchors: containment of an
independently compromised process already running as that same OS account (or
of a compromised host administrator) is outside this reviewer boundary and
must stop the landing workflow rather than be represented as valid review
evidence.

For an executable bootstrap/isolation check without a model request, the
Primary may invoke the reviewer payload through the same exact-base launcher
with `--diagnostic-bootstrap-only` and without `--codex-bin`. On macOS this runs
the real exact-base materialization, PHP attestation, and Seatbelt allow/deny
canaries. It writes only inside private system-temporary roots, never the user
home, and returns `review_evidence: false`; it can diagnose the harness but can
never satisfy a final-review or landing requirement.

After the final reviews are finding-free, record their canonical,
privacy-safe exact-head attestation on the PR and run the repository-owned
read-only verifier:

```bash
composer check:exact-head-mergegate -- --pr=<number-or-canonical-url> --reviewed-sha=<40-character-sha>
```

The verifier uses GitHub REST GET requests plus bounded, read-only GraphQL
queries. It must run from the exact reviewed `HEAD`, loads its policy from that
committed tree, and rejects local changes to the contract or mergegate
implementation. Its workflow parser runs isolated and accepts only the YAML
runtime file manifest and digest pinned by that reviewed policy.
It observes all normalized CI and review evidence twice. It reads PR identity
before, between, and after those bounded observations. All three PR reads and
both complete evidence observations must remain equal. It requires the open
non-draft PR, clean mergeability, the canonical successful CI run and every
blocking check to bind to that PR and SHA. Always-on checks must succeed;
diff-conditional checks must be either successful or explicitly skipped. It
also requires the three distinct review lenses from the machine contract in
one new, unedited, SHA-bound owner attestation with exact review-activity
watermarks and a privacy-safe review payload digest. Batched GraphQL edit
counts bind the attestation's unedited state plus each trusted formal review
and inline review comment, while only body digests enter the watermark. A still-active trusted
`CHANGES_REQUESTED` review, trusted watermark or payload drift, edited trusted
inline feedback, newer trusted review feedback, or a newer invalid attestation
marker invalidates that evidence. Missing, pending, duplicated, malformed,
stale, or wrong-suite evidence fails closed. The report contains no raw comment
body, reviewer identity, token, capability, or personal data.
Untrusted review activity neither grants authority nor vetoes landing. See
`docs/exact-head-mergegate.md`.

An exit `0` is required before `Ready to Merge`, but it does not perform the
merge. Use the compare-and-swap merge command from
`.codex/contracts/agent-workflow.json` on the same still-current SHA.

The PR is not done until:

- required blocking CI is green
- no open review findings remain
- the PR is mergeable
- the read-only exact-head mergegate passes on the current reviewed SHA
- required docs or migration notes are included
- the reviewed head, CI head, and current PR head are identical
- the issue is moved to `Done`
