## Linear

- Issue: <!-- z. B. ROB-24 -->
- Status vor PR: `In Progress`
- Status nach PR-Erstellung: `In Review`
- Status vor Merge: `Ready to Merge`

## Summary

<!-- Was wurde geaendert und warum? -->

## Scope

<!-- Was ist Teil dieses PRs? -->

## Out of Scope

<!-- Was wurde bewusst nicht umgesetzt? -->

## Risk Assessment

<!-- Risikolevel: low / medium / high -->
<!-- Nenne moegliche Regressionen und betroffene Bereiche -->

## Validation

<!-- Liste exakt die ausgefuehrten Kommandos -->
<!-- Beispiel:
docker compose run --rm php-fpm composer test
PRE_PR_RUN_COVERAGE=1 bash ./scripts/ci/pre_pr_full.sh
-->

## Follow-Ups

<!-- Offene Folgearbeiten mit Issue-Referenzen -->

## Reviewer A (Bugs/Regression/Security/Edge Cases)

- [ ] Laufzeitverhalten gegen Erwartung geprueft
- [ ] Edge Cases und Fehlerpfade geprueft
- [ ] Keine offensichtliche Security-Regression
- [ ] Findings dokumentiert oder explizit: keine Findings

## Reviewer B (Architektur/Lesbarkeit/Wartbarkeit)

- [ ] Architekturgrenzen und Struktur passen
- [ ] Code ist nachvollziehbar und wartbar
- [ ] Findings dokumentiert oder explizit: keine Findings

## Reviewer C (Tests/Regression/Flake-Risiko)

<!-- Pflicht fuer Authority-, Secret-, Identitaets-, Transaktions- und
Concurrency-Aenderungen; sonst N/A mit kurzer Begruendung. -->

- [ ] Positive, negative und Race-Pfade angemessen abgedeckt
- [ ] Nullmutation und Side-Effect-Grenzen bei Ablehnung geprueft
- [ ] Flake-Risiko und Aussagekraft der ausgefuehrten Tests geprueft
- [ ] Findings dokumentiert, explizit keine Findings oder begruendet N/A

## Merge Readiness

- [ ] Blocking CI ist gruen
- [ ] Keine offenen Review-Findings
- [ ] PR-Head, CI-Head und final reviewter Head sind identisch
- [ ] PR ist mergeable
- [ ] Noetige Docs/Migrationshinweise sind enthalten

## Workflow References

- `.codex/contracts/agent-workflow.json`
- `WORKFLOW.md`
- `AGENTS.md`
