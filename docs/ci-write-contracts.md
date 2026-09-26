# CI Write Contracts

Mutation-kritische Contract-Smokes fuer Booking- und API-Write-Pfade.

## Scope

- Booking write-path contracts (`POST /booking/register`, `GET /booking/reschedule/{hash}`, `POST /booking_cancellation/of/{hash}`)
- API OpenAPI write contracts (`POST/PUT/DELETE` auf `customers` + `appointments`)

Der Booking-Contract legt einen normalen Termin weiterhin mit
`manage_mode=false` und ohne bestehende IDs an. Vor einem bestehenden
Termin-Update ruft derselbe HTTP-Client dagegen die kanonische
Reschedule-Route auf. Nur die dabei serverseitig und sitzungsgebunden erzeugte
Einmal-Authority darf das anschliessende Update freigeben. `manage_mode`, IDs
oder der Route-Hash allein reichen nicht. Der vollstaendige Sicherheitsvertrag
steht in [Public Reschedule Authority](security/public-reschedule-authority.md).

Der Controller `Booking::register()` akzeptiert ausschließlich POST. Andere
Methoden, die ihn erreichen, erhalten vor der Auswertung von Query-Daten oder
einer Reschedule-Authority HTTP 405 mit `Allow: POST`. Der globale CORS-Preflight
beantwortet OPTIONS bereits vor dem Controller und führt keine Buchung aus.
Insbesondere darf ein GET weder einen Termin anlegen noch eine bereits
freigegebene Umbuchung auslösen oder deren Einmal-Authority verbrauchen.
Die isolierten HTTP-/DB-Tests in `BookingMethodHttpTest` prüfen beide Fälle samt
Nichtmutation und anschließendem gültigem POST.

Die Backoffice-Endpunkte `customers/store` und `services/store` legen nur neue
Datensätze an. Eine mitgesendete bestehende ID wird abgewiesen; Änderungen laufen
über `update` mit Bearbeitungsrecht und den bestehenden Zugriffsprüfungen.
`EntityStoreAuthorizationTest` prüft diese Trennung einschließlich normalem
Anlegen, berechtigtem Bearbeiten und der bestehenden E-Mail-Prüfung.

Öffentliche Buchungskonflikte liefern einheitlich HTTP 409 mit dem Hinweis,
dass die angefragte Zeit nicht verfügbar ist. Die Antwort unterscheidet nicht
zwischen einem belegten Zeitfenster und einer Überschneidung beim Kunden.
Die Überschneidungsprüfung und das vollständige Zurückrollen bleiben erhalten.

## Öffentliche Stornierung

`POST /booking_cancellation/of/{hash}` verlangt eine nicht leere gespeicherte
Linkberechtigung und aktivierte Buchung. Der Controller öffnet nach dem ersten
Lookup eine äußere Transaktion, sperrt den Termin und prüft Hash, Terminart und
`book_advance_timeout` erneut auf dem gesperrten Datensatz. Wie die bestehende
Verwaltungsseite lehnt er `start_datetime < now + timeout` ab; Gleichheit bleibt
erlaubt. Der zeitzonenlose gespeicherte Termin und die aktuelle Zeit werden dabei
sekundengenau in der Zeitzone des zugehörigen Anbieters ausgewertet. Nicht
existente lokale Uhrzeiten während eines Zeitzonenwechsels werden nicht still
normalisiert, sondern fail-closed abgelehnt. Der geschachtelte Modellaufruf
löscht Puffer und Termin; erst der äußere Commit bestätigt den Erfolg.
Ablehnungen und Fehler rollen vorher zurück. Dies ist die bestehende
Termin-/Kind-Sperrfolge, keine neue globale Elternsperre.

Die isolierten HTTP-Tests prüfen Frist einschließlich abweichender
Anbieterzeitzone, Methode, deaktivierte Buchung, Nichtmutation und erfolgreiche
frühe Stornierung. Der Konkurrenztest beobachtet den tatsächlichen
Datenbank-Lock-Wait einer zweiten HTTP-Verbindung und prüft anschließend
geänderte Startzeit, ausgetauschten Hash und gelöschten Datensatz. Diese lokalen
Nachweise ersetzen keine Produktionsprüfung.

## Local Repro (Docker CI-Parity)

```bash
docker compose up -d mysql php-fpm nginx
until docker compose exec -T mysql mysqladmin ping -h localhost -uroot -psecret --silent; do sleep 2; done
until docker compose exec -T mysql mysql -uuser -ppassword -e "USE easyappointments; SELECT 1;" >/dev/null 2>&1; do sleep 2; done
for attempt in 1 2 3; do docker compose exec -T php-fpm php index.php console install && break; [ "$attempt" -eq 3 ] && exit 1; sleep 3; done

docker compose exec -T php-fpm composer contract-test:booking-write -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --booking-search-days=14 --retry-count=1

docker compose exec -T php-fpm composer contract-test:api-openapi-write -- \
  --base-url=http://nginx --index-page=index.php --openapi-spec=/var/www/html/openapi.yml \
  --username=administrator --password=administrator \
  --retry-count=1 --booking-search-days=14

docker compose down -v --remove-orphans
```

Optional (beide hintereinander):

```bash
docker compose exec -T php-fpm composer contract-test:write-path -- \
  --base-url=http://nginx --index-page=index.php \
  --username=administrator --password=administrator \
  --openapi-spec=/var/www/html/openapi.yml \
  --retry-count=1 --booking-search-days=14
```

Cross-document write-path and evidence invariants are machine-readable in the
[agent workflow contract](../.codex/contracts/agent-workflow.json).

## Allgemeiner Mutation-Vertrag

Jeder mutation-kritische öffentliche Write folgt derselben festen Reihenfolge:

`Route -> Request-Klassifikation -> serverseitige Authority -> feste Lock-/Transaktionsgrenze -> Mutation -> Post-Commit-Effekte`.

Die Route klassifiziert den Request; die Autorisierung wird serverseitig aus
kanonischer Authority und aktuellem Datenbankzustand entschieden. Caller-
supplied Flags, IDs, Hashes, Tokens oder Pfade reichen dafür nie aus. Lock und
Transaktion grenzen die Prüfung und die anschließende Mutation gegen
konkurrierende Writes ab. Erst nach erfolgreichem Commit dürfen Nebenwirkungen
wie Events, Benachrichtigungen oder Cleanup ausgelöst werden. Wird ein Contract
verletzt, wird atomar abgelehnt: Es gibt keine Teilmutation und keine Post-
Commit-Effekte. Bei konkurrierenden Prüfungen sind eine zweite DB-Verbindung
und eine globale Lock-Reihenfolge ausdrücklich mitzudenken, damit keine
Prüfung ihre eigene uncommitted Sicht als Authority verwendet oder ein
Deadlock entsteht.

## API-Einstellungen im Backoffice

`api_settings/save` verlangt System-Settings-Edit-Berechtigung und POST vor
DTO-Verarbeitung und Modellzugriff. Der bestehende globale CSRF-Schutz bleibt
für POST aktiv; die Oberfläche sendet bereits diesen Request-Typ. Die isolierten
Controller-Regressionen belegen die Aufrufreihenfolge mit Test-Doubles, keine
echte HTTP-/CSRF-Verifikation. Der API-Controller übergibt den vollständigen
Batch an `Settings_model::save_batch`; Transaktionszuständigkeit und die getrennten
Datenbanknachweise beschreibt [der atomare Schreibvertrag](atomic-write-contracts.md#api-settings-batches).

## Weitere Einstellungen im Backoffice

Die Save-Aktionen von General-, LDAP-, Legal-, Business-, Booking-, Matomo-
und Google-Analytics-Einstellungen prüfen zuerst die System-Settings-Edit-
Berechtigung und verlangen anschließend POST vor DTO-Verarbeitung, Abfragen
oder Speicherung. Andere Methoden erhalten nach erfolgreicher Berechtigungsprüfung
405 mit `Allow: POST`. Die vorhandenen Oberflächen senden bereits POST.

Die gemeinsame isolierte Controller-Matrix prüft Berechtigungsreihenfolge,
Methodenablehnung und den unveränderten normalen Modell-Handoff mit Test-Doubles.
Sie belegt keine echte HTTP-/CSRF-Verifikation. Sechs Save-Aktionen übergeben
den vollständigen Batch an `Settings_model::save_batch`; Business und Booking
behalten ihre vorherige Feldfilterung bei. General Settings behält dagegen die
vollständige Vorvalidierung und vorbereiteten IDs bei und speichert diese in
einer gemeinsamen Transaktion. Die getrennten Datenbanknachweise und Grenzen
beschreibt [der atomare Schreibvertrag](atomic-write-contracts.md#backoffice-settings-batches).

Die separate Aktion `business_settings/apply_global_working_plan` verlangt
ebenfalls zuerst die bestehende Edit-Berechtigung und anschließend POST. Ihre
beabsichtigte Übertragung des Editorplans auf alle serverseitig ausgewählten
Anbieter bleibt erhalten. Die gemeinsame Transaktion und getrennten
Testnachweise beschreibt [der atomare Arbeitsplan-Vertrag](atomic-write-contracts.md#global-working-plan-application).

## Unavailabilities API v1

Die authentifizierten Schreibaktionen verlangen ihr kanonisches HTTP-Verb:
`store` POST, `update` PUT und `destroy` DELETE. Das gilt auch bei direktem
Aufruf eines Controller-Alias. Ein falsches Verb erhält 405 mit `Allow`, bevor
eine Schreib-Payload gelesen oder ein Datensatz verändert wird.

Bei PUT bestimmt ausschließlich die URL den Zieldatensatz. Eine mitgesendete
`id` muss eine identische JSON-Ganzzahl sein; eine abweichende oder anders
typisierte `id` wird mit 400 vor jeder Mutation abgewiesen. Ohne Body-ID bleibt
der URL-Datensatz das Ziel. Das Modell akzeptiert für Lookup und Änderung nur
Datensätze vom Typ Nichtverfügbarkeit. Geschützte Typ- und Elternfelder können
bei einer Änderung nicht umgeschrieben werden. Generierte Termin-Puffer bleiben gegen
direkte Änderung oder Löschung geschützt. UPDATE und DELETE binden ihre
Datenbankoperation zusätzlich an Typ und fehlende Elternverknüpfung, damit ein
regulärer Termin samt abhängigen Puffern nicht über diesen Pfad verändert wird.

Der Kalender-Endpunkt filtert die schreibbaren Felder. Bei vorhandenen
Nichtverfügbarkeiten sperrt er zuerst die bisherigen und angefragten
Anbieterzeilen und dann den manuellen Datensatz; eine inzwischen geänderte
Anbieterzuordnung führt vor der Mutation zum Abbruch. Die Kalender-Änderung
und -Löschung besitzen eine gemeinsame Transaktionsgrenze. Diese Sperrfolge
schützt den geprüften Datensatz, verspricht aber keine anwendungsweite
Serialisierung aller Anbieter-Berechtigungsänderungen.

Die isolierten HTTP-Regressionen prüfen Normalfälle, unautorisierte Anfragen,
abweichende Body-IDs, reguläre Termin-IDs, generierte Puffer und direkte Aliase
mit einem Vorher-/Nachher-Abgleich der eigenen Testdatensätze. Die Modelltests
prüfen die Typgrenze bei direkten Kalender-/Modellaufrufen. Ein gezielter
Zwei-Verbindungs-Test verschiebt die Anbieterzuordnung zwischen erster
Autorisierung und Sperre und erwartet eine Ablehnung ohne Änderung. Lokale
Nachweise belegen keine produktive Auslieferung.

## Blocked Periods API v1

Die API verwaltet globale Sperrzeiten. Admin-Basic-Authentifizierung oder der
konfigurierte globale Bearer-Token sind die bestehenden API-Authorities; eine
Sperrzeit hat keinen eigenen Anbieter oder benutzerspezifischen Eigentümer.
`store`, `update` und `destroy` verlangen auch auf direkten Controller-Aliasen
POST, PUT beziehungsweise DELETE. Ein anderes Verb erhält 405 mit dem passenden
`Allow`-Header, bevor eine Schreib-Payload oder ein Zieldatensatz verarbeitet
wird.

Bei PUT ist allein die URL-ID das Ziel. Eine mitgesendete `id` muss dieselbe
als Ganzzahl typisierte ID sein; eine abweichende, leere oder anders typisierte
ID wird vor der Dekodierung mit 400 abgewiesen. Ohne Body-ID bleibt die URL-ID
erhalten.
POST ignoriert eine mitgesendete ID und legt einen neuen Datensatz an; DELETE
verwendet ausschließlich die URL-ID. Ein Zeitfenster mit Startzeit ab oder nach
der Endzeit wird vor einer Mutation abgewiesen. Die globale Sperrzeit wird von
Kalender- und Buchungsverfügbarkeitsabfragen gelesen; der API-Schreibpfad hat keine
zusätzlichen Kalender- oder Puffer-Schreibeffekte.

`BlockedPeriodsApiHttpWriteTest` prüft diese Grenzen mit eigenen A/B-Datensätzen,
authentifizierten HTTP-Anfragen, positiven Schreibkontrollen und vollständigen
Vorher-/Nachher-Zeilenvergleichen in einem frischen isolierten Datenbank-Stack.
Backoffice-Tests belegen diese API-v1-Grenzen nicht. Der lokale Nachweis ersetzt
keinen produktiven Release- oder Verfügbarkeitsnachweis.

## Service Categories API v1

Die authentifizierten Schreibaktionen verwenden auch bei direkten
Controller-Aliasen ausschließlich POST für `store`, PUT für `update` und DELETE
für `destroy`. Ein falsches Verb erhält 405 mit passendem `Allow`-Header vor
Payload- oder Datensatzverarbeitung. Die bestehenden Authorities sind
Admin-Basic und der konfigurierte globale Bearer; Provider- und ungültige
Zugangsdaten erteilen keine Schreibberechtigung.

Bei PUT bestimmt allein die URL-ID die Kategorie. Eine Body-`id` muss dieselbe
JSON-Ganzzahl sein; abweichende, leere und anders typisierte IDs werden mit
400 vor jeder Mutation abgewiesen. Ein PUT ohne Body-ID behält das URL-Ziel.
Leere oder nur aus unbekannten Eigenschaften bestehende PUT-Payloads werden
ohne Änderung mit 400 abgewiesen. POST ignoriert eine mitgesendete ID und
erzeugt einen neuen Datensatz; DELETE verwendet ausschließlich die URL-ID.

Die bestehende Fremdschlüsselregel `ON DELETE SET NULL` löst bei erfolgreicher
Löschung einer Kategorie die Zuordnung verknüpfter Services, ohne die Services
selbst zu löschen. Kategorieänderungen wirken über die bestehenden Lese-Joins
auf die Darstellung dieser Services; der Kategorien-Schreibpfad führt keine
zusätzlichen Buchungs- oder Kalenderwrites aus.

POST und PUT halten Speicherung, erneutes Lesen und API-Antwortprojektion in
einer Transaktion. Schlägt ein Schritt vor dem Commit fehl, darf eine
Fehlerantwort keine persistierte Kategorieänderung hinterlassen. Ein
fehlgeschlagenes Datenbank-DELETE darf nicht als HTTP 204 ausgegeben werden.
Kontrollierte lokale Fehlerinjektion prüft die Antwort zusammen mit dem
Datenbankzustand; Verbindungsabbrüche nach einem erfolgreichen Commit bleiben
eine gesonderte, hier nicht gelöste Transportgrenze.

`ServiceCategoriesApiHttpWriteTest` prüft diese Grenzen über echtes isoliertes
HTTP und eine frische Datenbank mit eigenen A/B-Kategorien und eigenem Service.
Er vergleicht die vollständigen eigenen Zeilen, die Verfügbarkeit des eigenen
verknüpften Service vor und nach der Kategorielöschung und die Bereinigung.
`ServiceCategoriesApiPostWriteFailureTest` prüft die Controller-Antwort und den
echten isolierten DB-Zustand nach injizierten Fehlern beim erneuten Lesen;
`ServiceCategoriesApiDeleteStatusTest` prüft die Antwort bei einer injizierten
fehlgeschlagenen Low-Level-Löschung.
Dieser lokale Nachweis belegt weder produktive Auslieferung noch alle
konkurrierenden oder fehlerinduzierten Datenbankabläufe.

## Staff API v1 PUT

Bei `PUT /api/v1/admins/:id`, `providers/:id` und `secretaries/:id` bestimmt
die URL den zu ändernden Mitarbeiterdatensatz. Eine Body-`id` darf nur als
identische JSON-Ganzzahl wiederholt werden; abweichende oder anders typisierte
IDs werden mit 400 vor der Modellmutation abgewiesen. Ohne Body-ID bleibt das
URL-Ziel verbindlich. Ein direkter `update`-Controller-Alias darf nur mit PUT
schreiben und lehnt andere Methoden mit 405 ab. Das bloße Vorhandensein einer
ID in der gemeinsamen `users`-Tabelle erteilt keine Autorität, einen Datensatz
einer anderen Mitarbeiterrolle zu ändern.

Die isolierten Staff-HTTP-Tests verwenden je Rolle eigene A/B-Datensätze und
prüfen Antwort, vollständige Benutzerzeilen, Einstellungen und relevante
Zuordnungen vor und nach abgewiesenen sowie passenden PUT-Anfragen. Sie belegen
ihren lokalen HTTP-/Datenbanklauf, nicht die produktive Auslieferung oder alle
konkurrierenden Änderungen.

## Admins API v1 direkte Schreibaliase

Die kanonischen Admin-Routen verwenden POST für `store` und DELETE für
`destroy`. Auch direkt erreichbare `Admins_api_v1`-Controller-Aliase erzwingen
diese Methoden nach der API-Authentifizierung und vor Payload-Auswertung,
Datensatzsuche oder Mutation. Abweichende Methoden erhalten 405 mit
`Allow: POST` beziehungsweise `Allow: DELETE`. Der bestehende Schutz des
letzten Administrators bleibt eine eigene Modellregel.

`StaffSettingsApiHttpTest` prüft mit eigenen synthetischen Admin-Datensätzen,
Basic und Bearer die Ablehnung der direkten Aliase samt vollständigem
Benutzer-/Einstellungszustand. Die vorhandenen kanonischen POST- und
DELETE-Tests bleiben Positivkontrollen. Das ist lokaler HTTP-/Datenbanknachweis,
kein produktiver Schreibtest.

## Secretaries API v1 direkte Schreibaliase

Die kanonischen Secretary-Routen verwenden POST für `store` und DELETE für
`destroy`. Auch direkt erreichbare `Secretaries_api_v1`-Controller-Aliase
erzwingen diese Methoden nach der API-Authentifizierung und vor
Payload-Auswertung, Datensatzsuche oder Mutation. Abweichende Methoden erhalten
405 mit `Allow: POST` beziehungsweise `Allow: DELETE`; die bestehenden
Provider- und Rollenbeziehungen bleiben unverändert.

`StaffSettingsApiHttpTest` prüft mit eigenen synthetischen Secretary-Datensätzen
und gültiger Basic- beziehungsweise Bearer-Authentifizierung die Ablehnung der
direkten Aliase samt vollständigem Benutzer-, Einstellungs- und
Provider-Zustand. Das ist lokaler HTTP-/Datenbanknachweis, kein produktiver
Schreibtest.

## Settings API v1

Die generische Settings API erlaubt authentifizierten Admin-Basic- und
Bearer-Clients das Lesen gespeicherter Werte sowie `PUT /api/v1/settings/:name`.
Der API-Token bleibt nach dem bestehenden privilegierten Vertrag sichtbar;
dieser Methodenfix führt keine neue Namens- oder Wert-Whitelist ein.
Ein direkt erreichbarer `Settings_api_v1/update/:name`-Alias darf nur mit PUT
schreiben. GET und POST werden vor der Auswertung von `value` mit 405 und
`Allow: PUT` abgewiesen. Für Settings existiert kein DELETE-Endpunkt.

`StaffSettingsApiHttpTest` prüft dies über das echte lokale HTTP-Routing mit
synthetischen eigenen Einstellungen: abgewiesene direkte Aliase lassen die
vollständige Zeile unverändert, erlaubtes PUT persistiert. Das belegt die
isolierte Testumgebung, nicht die produktive Auslieferung.

## Write-only Integrationsgeheimnisse

Die authentifizierte REST-v1-API behandelt
`providers.settings.googleToken` und `providers.settings.caldavPassword`
als reine Write-Inputs. Provider-Collection, -Detail, -Create- und
-Update-Antworten durchlaufen vor
jeder Query-Projektion dieselbe zentrale secret-freie API-Kodierung.
`fields`, `with`, Suche und Sortierung dürfen die Werte daher weder direkt
noch über alternative snake_case-Namen zurückholen.

Beim Update startet die Dekodierung vom aktuellen Datensatz: ein ausgelassenes
Secret bleibt unverändert, ein expliziter String ersetzt es und `null` löscht
es. Typ- und Längenfehler werden vor der Mutation mit wertfreien Meldungen
abgelehnt. OpenAPI führt diese Felder nur in Payload-Schemas mit
`writeOnly: true`; Record-Schemas und Beispiele enthalten sie nicht.
Die Kalender-Synchronisierung ist entfernt; historische Kalenderfelder bleiben
als Daten erhalten und aktivieren keine Synchronisierung. Die ungenutzte
Anwendungs-Webhook-Funktion ist entfernt; ihre historischen Tabellen und Daten
bleiben ohne ausführbaren Verwaltungs- oder Versandweg erhalten.

## Evidence-Privacy-Vertrag

Logs, Reports, Tests, PR-Evidenz und Linear-Einträge enthalten weder Secrets
noch Capability-, Authority- oder Tokenwerte, request- oder
personenbezogene Hashes oder personenbezogene Daten. Technische Commit-, Run-
und anonymisierte Fixture-IDs bleiben für Exact-Head- und CI-Evidenz zulässig.
Nachweise beschreiben nur Status, Typen, Zeitpunkte und redigierte
Fehlerklassen. Retries sind ausschließlich für transiente Laufzeitfehler
zulässig; bei einem Contract-Mismatch wird weder automatisch wiederholt noch
die Evidenz durch weitere Mutation vergrößert.

## Reports / Artifacts

- Booking write report: `storage/logs/ci/booking-write-contract-<UTC>.json`
- API write report: `storage/logs/ci/api-openapi-write-contract-<UTC>.json`
- CI uploads both report globs always (`if: always()`), inklusive Failure-Diagnostics

Jeder Report enthaelt:

- `run_id`
- check status + `duration_ms`
- retry metadata (`max_retries`, `attempts`, retry events)
- cleanup summary (`created`, `deleted`, `failures`)

Booking-Reports redigieren Route-Hashes, serverseitige Authority-/Tokenwerte,
Customer-Payloads und personenbezogene Felder. Diese Werte bleiben nur fuer die
laufende In-Process-Pruefung und das Cleanup verfuegbar.

## Flake Control

- Maximal ein Retry (`--retry-count=1`) nur bei transient runtime errors:
  - timeout / timed out
  - 502 / 503 / 504
  - connection reset/refused, failed/could-not-connect
- Kein Retry bei Contract-Mismatch (Status-/Schema-/Typverletzung)

## CI Jobs

- `write-contract-booking` ist aktuell blockierend.
- `write-contract-api` ist aktuell blockierend.
- Beide changed-file gated via `changes` outputs:
  - `write_contract_booking`
  - `write_contract_api`

Die ausführbare CI-Konfiguration steht in
[.github/workflows/ci.yml](../.github/workflows/ci.yml). Der
[agent workflow contract](../.codex/contracts/agent-workflow.json) ist die
kanonische Prüfquelle für die erwarteten Blocking-Jobs sowie die exakte
Ausführung der beiden Write-Contract-Gates. Der Readiness-Check verlangt, dass
beide Quellen übereinstimmen. Jeder vertraglich blockierende Job muss im
Workflow vorhanden sein. Jeder Workflow-Job muss im Maschinenvertrag genau
einmal als blockierend oder advisory klassifiziert sein. Neue oder umbenannte
Jobs schlagen bis zu dieser bewussten Einordnung fail-closed fehl; die
Advisory-Klassifikation erzeugt dabei weder Blocking-Authority noch einen
Ausfuehrungs-Fingerprint. Die
versionierte Actions-Expression-Grammatik des Vertrags ist bewusst eng; nicht
unterstützte Syntax wird fail-closed abgelehnt und
erfordert eine gemeinsame Änderung von Vertrag, Parser und Regressionstests.
Die strikte Failure-Control-Policy ist im Checker versioniert. Der Vertrag
referenziert nur ihre Policy-ID; unbekannte IDs schlagen fail-closed fehl. Eine
neue Policy-Version verlangt eine bewusste Checker- und Regressionstest-
Aenderung, nicht die parallele Pflege derselben Keylisten an mehreren Stellen.
Auch die Schritte nach dem Assertion-Gate sind exakt festgelegt, damit keine
ungeprüfte Evidence-Ausgabe oder nachgelagerte Aktion ergänzt werden kann.
Der aus Triggern, Berechtigungen, globaler Umgebung, Defaults und Concurrency
bestehende Workflow-Ausfuehrungsrahmen sowie jeder
`fingerprinted_execution`-Job besitzen einen eigenen kanonischen SHA-256-
Nachweis. Dadurch nennt eine Abweichung genau den betroffenen Rahmen oder Job.
`exact_execution`-Jobs werden stattdessen direkt aus ihrer Job-Klasse abgeleitet
und vollstaendig gegen ihren strukturierten Vertrag geprueft; eine parallele
Ankerliste oder ein zusaetzlicher Hash ist nicht erforderlich. Dieser Vertrag
bindet Abhaengigkeiten, Condition, Runner, Timeout und die vollstaendige
Step-Folge; zusaetzliche ausfuehrungsrelevante Job-Keys werden abgewiesen. Job-
und Step-Anzeigenamen sowie die Reihenfolge in `needs`, der Event-Kurzform,
Trigger-`types` und `workflows` werden als nicht ausfuehrungsrelevant
normalisiert.
Glob-Filter behalten wegen reihenfolgeabhaengiger Negationen ihre Reihenfolge;
Job- und Step-`if`-Ausdruecke werden ueber die versionierte Grammatik in eine
kanonische semantische Form gebracht. Nicht unterstuetzte Ausdruecke schlagen
fail-closed fehl; ausfuehrungsrelevante Inhalte bleiben vollstaendig gebunden.
Jeder Workflow-/Job-/Step-`shell`-Override und jede nicht ausdruecklich
ausgenommene `continue-on-error`-Deklaration lassen die Readiness-Pruefung
fehlschlagen. `strict-v2` erlaubt ausschliesslich den optionalen Archivtransport:
`actions/cache/restore@v4` und `actions/cache/save@v4` im Defense-Job duerfen
mit literalem `continue-on-error: true`, exakt einer Minute Timeout und ohne
`run` ausgefuehrt werden. Build, Tests und Bereinigung bleiben blocking; der
vollstaendige Job-Fingerprint bindet auch Cache-Keys, Pfade und Conditions. Advisory-Signal-Jobs gehoeren nicht zum
Blocking-Vertrag, bleiben aber namentlich klassifiziert, damit kein neuer
Blocking-Job versehentlich ausserhalb des Vertrags landet.
Bei einer beabsichtigten Aenderung einer fingerprinted Blocking-Ausfuehrung
nennt der Readiness-Report die abweichenden Komponenten samt erwartetem und
aktuellem Nachweis. Vertragswerte werden erst nach Review der zugehoerigen
Workflow-Aenderung aktualisiert; Hashes sind keine alternative Freigabequelle.

## Rollback Policy

Ein Rollback der Blocking-Eigenschaft ist nur als ausdrücklich begründete,
zeitlich begrenzte CI-Änderung zulässig; dabei bleibt
`.github/workflows/ci.yml` die alleinige CI-Quelle. Diese Dokumentation
schwächt kein Gate ab und beschreibt keine alternative Warnphase. Die in
[WORKFLOW.md](../WORKFLOW.md) geforderte Rückkehrfrist und das Follow-up-Issue
bleiben verbindlich.

Die Legacy-Aliase `backend_api/ajax_save_settings` und
`backend_api/ajax_apply_global_working_plan` verwenden explizite
Location-Weiterleitungen mit Status 307, damit POST und Request-Body beim
Client erhalten bleiben. Die Zielcontroller prüfen weiterhin Berechtigung
und POST; die normale CSRF-Prüfung bleibt aktiv. Der isolierte Alias-Test
belegt Ziel, Redirect-Methode und Status sowie unveränderte Eingabedaten,
keine vollständige HTTP-/Browser-/CSRF-Weiterleitungskette.

Der isolierte gewöhnliche HTTP-Test für `backend_api/ajax_save_settings`
prüft zusätzlich eine echte Anmeldung, Status 307 und das feste lokale Ziel,
dann einen kontrollierten zweiten POST mit identischem Formular und CSRF-Token.
Er verändert nur eine eigene synthetische Einstellung und prüft Persistenz sowie
Bereinigung. Dieser Nachweis umfasst weder automatische Browser-Weiterleitung
noch den globalen Arbeitsplan-Endpunkt oder Produktion.

Die Backoffice-Aktionen für Admins und Sekretariate prüfen nach der jeweiligen
Berechtigung zusätzlich POST und antworten bei anderen Methoden mit 405 und
`Allow: POST`, bevor DTO- oder Modellzugriff erfolgt. Die Legacy-Löschaliase
leiten weiterhin mit 307 und unverändertem Request-Body an feste Destroy-Ziele
weiter; die Save-Aliase bleiben eine gesonderte Kompatibilitätsfrage.

Die isolierten Controller-Regressionen prüfen `store`, `update` und `destroy`
beider Controller mit synthetischen DTO- und Modell-Doubles sowie die
Redirect-Argumente der beiden Löschaliase. Sie belegen keine echte HTTP-Kette,
Framework-CSRF-Prüfung oder Datenbankpersistenz.

Der gemeinsame `abort()`-Helper sendet explizit übergebene Header unmittelbar,
bevor `show_error()` den Request beendet. Ein lokaler HTTP-Helper-Test prüft
den tatsächlich ausgegebenen Status, `Allow`-Header und Fehlertext dieses
Abbruchpfads. Das ergänzt die Controller-Doubles, ohne deren Aussagen auf
Routing, Authentifizierung oder Datenbankverhalten auszuweiten.

Die Customer-Store-, Update- und Destroy-Aktionen prüfen nach der Berechtigung
zusätzlich POST und antworten sonst mit 405 und `Allow: POST`, bevor DTO- oder
Modellzugriff erfolgt. Der Customer-Löschalias verwendet eine feste
307-Weiterleitung. Die isolierten Controller-Tests halten Sichtbarkeitsregeln,
Datensatz-Zugriffsprüfungen und synthetische DTO-/Modellübergaben fest; sie
belegen keine echte DB-, HTTP- oder CSRF-Kette.
