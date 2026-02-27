# PHPStan Autofix Automation (Offline-First)

Dieses Dokument beschreibt den stabilen Betrieb der PHPStan-Autofix-Automation
fuer `forscherhaus-appointments` mit moeglichst geringer Netzabhaengigkeit.

## Zielbild

- Dedizierter, persistenter Worktree fuer die Automation
- PHP-/Composer-/PHPStan-Schritte in Docker (`php-fpm`)
- Git-/PR-Schritte auf dem Host (`gh`)
- Vorbereitete Composer-Dependencies und Cache fuer Offline-Faelle
- Deterministische Statusausgabe mit `status`, `reason`, `verification`

## One-Time Setup (Arbeitsumgebung)

### 1) Persistenten Automation-Worktree anlegen

```bash
git worktree add --detach ../forscherhaus-appointments-phpstan-auto main
```

### 2) In den Automation-Worktree wechseln

```bash
cd ../forscherhaus-appointments-phpstan-auto
```

### 3) Bootstrap ausfuehren

Das Skript startet bei Bedarf `php-fpm`, seeded den Composer-Cache in den
Container, fuehrt `composer install` aus, prueft `vendor/bin/phpstan` und
validiert `gh auth`.

```bash
COMPOSE_PROJECT=fh-phpstan-auto bash ./scripts/automation/bootstrap-phpstan-autofix.sh
```

## Runner Invocation

Die eigentliche Automation wird ueber das Runner-Skript gestartet:

```bash
COMPOSE_PROJECT=fh-phpstan-auto bash ./scripts/automation/run-phpstan-autofix.sh
```

Voraussetzungen fuer den Runner:

- `codex` CLI installiert und eingeloggter Zugriff
- `jq`, `docker`, `gh` verfuegbar

Optional:

- `RUN_BOOTSTRAP=0` deaktiviert den vorgeschalteten Bootstrap-Aufruf.
- `CODEX_MODEL=<modellname>` erzwingt ein bestimmtes Codex-Modell.

Der Runner druckt immer exakt dieses Format auf `stdout`:

```text
status: <fixed-and-pr|report-only|skipped-draft-exists>
reason: <machine-friendly-reason>
verification:
  - <command-1>
  - <command-2>
```

## Laufzeit-Preflight (manuell / fuer Debug)

### Pflichtdateien

```bash
test -f composer.json && test -f composer.lock && test -f phpstan.neon.dist
```

### PHPStan-Binary

```bash
docker compose -p fh-phpstan-auto exec -T php-fpm sh -lc 'test -x vendor/bin/phpstan'
```

### GitHub-Auth

```bash
gh auth status -h github.com
```

## Offline Recovery

Wenn `composer install` wegen Netzproblemen scheitert:

1. Sicherstellen, dass ein persistenter Worktree verwendet wird.
2. Sicherstellen, dass der Compose-Projektname stabil bleibt
   (`COMPOSE_PROJECT=fh-phpstan-auto`), damit der Container mit `/tmp`-Cache
   weiterverwendet wird.
3. Host-Cache pruefen:
   `ls -la ~/Library/Caches/composer`
4. Bootstrap erneut ausfuehren, damit der Host-Cache in den Container
   synchronisiert wird:
   `COMPOSE_PROJECT=fh-phpstan-auto bash ./scripts/automation/bootstrap-phpstan-autofix.sh`
5. Wenn weiterhin kein Netz verfuegbar und kein Cache vorhanden ist:
   Lauf korrekt als `status: report-only` mit
   `reason: composer-install-failed` beenden.

## Contracts der Automation

- Ausgabe muss immer enthalten:
  - `status:`
  - `reason:`
  - `verification:` (Kommandos in Ausfuehrungsreihenfolge)
- Erlaubte `status`-Werte:
  - `fixed-and-pr`
  - `report-only`
  - `skipped-draft-exists`
- Exakter PR-Gate-Check (unveraendert):

```bash
gh pr list --state open --search "is:draft head:codex/phpstan-auto-" --json number,headRefName,title
```

- Branch-Praefix:
  - `codex/phpstan-auto-`
- Label-Regeln:
  - `codex` immer setzen
  - `codex-automation` nur setzen, wenn Label existiert

## Reason Codes (Referenz)

- `missing-required-files`
- `composer-install-failed`
- `missing-phpstan-binary`
- `no-actionable-issue`
- `ambiguous-fix`
- `php-lint-failed`
- `post-fix-phpstan-failed`
- `existing-auto-draft-pr`
- `pr-gate-failed`
- `issue-fixed-and-draft-opened`

## Verifikation fuer die Vorbereitung

Nach erfolgreichem Bootstrap sollten diese Checks gruen sein:

```bash
docker compose -p fh-phpstan-auto exec -T php-fpm sh -lc 'test -x vendor/bin/phpstan'
docker compose -p fh-phpstan-auto exec -T php-fpm sh -lc 'composer phpstan:application'
gh auth status -h github.com
```
