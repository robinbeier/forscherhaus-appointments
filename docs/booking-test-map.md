# Buchungs-Testkarte

Diese Karte ordnet die vorhandenen Tests nach Nutzerreise und Testebene. Sie
ist eine Orientierung, keine vollständige Testliste.

## Termin finden

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Buchungsseite lädt Dienste/Anbieter | `tests/Integration/Controllers/BookingReadAvailabilityControllerFlowTest.php::testIndexExposesBookingBootstrapForPublicFlow` | Verhaltens-Integration; fehlender öffentlicher Bootstrap bzw. versehentlich aktivierter Manage-Modus |
| Verfügbare Stunden (konkreter oder beliebiger Anbieter) | dieselbe Datei: `testGetAvailableHoursReturnsArrayForSpecificProvider`, `testGetAvailableHoursSupportsAnyProviderSentinel` | Verhaltens-Integration; Antwortform und Any-Provider-Kompatibilität |
| Nicht verfügbare Tage | `.../BookingReadAvailabilityControllerFlowTest.php::testGetUnavailableDatesReturnsArrayOrMonthUnavailableFlag` | Verhaltens-Integration; Datumsformat oder Monatsstatus |
| Umbuchungs-Ausschluss bei Stunden und Tagen über getrennte HTTP-Sitzungen | `tests/Integration/Controllers/BookingAvailabilityAuthorityHttpTest.php` | FH_DEFENSE_ISOLATED HTTP/DB; ein eigener belegter Termin bleibt ohne passende sitzungsgebundene Berechtigung sichtbar belegt, nur die berechtigte Sitzung mit kanonischer ID darf ihn für die Umbuchung ausnehmen; Termin und Berechtigung bleiben nach Reads unverändert, Fixtures werden bereinigt |
| Globale Sperrzeiten stimmen mit öffentlicher Verfügbarkeit und Direktbuchung überein | `tests/Integration/Controllers/BookingBlockedPeriodsHttpTest.php::testPublicAvailabilityAndBookingAgreeAcrossAFullPartialAndBoundaryBlock`, `::testSubMinuteGlobalBlockRemovesMinuteSlotAndRejectsExactPostWithoutMutation`, `::testNonzeroSecondsStartIsRejectedBeforeAvailabilityWithoutMutation`, `::testNonzeroSecondsRescheduleIsRejectedBeforeAuthorityClaim` | FH_DEFENSE_ISOLATED HTTP/DB; synthetische Woche vor/während/nach Vollsperre, partielle Sperre und exklusive Grenzen im Verfügbarkeitspfad; Sekundenwerte werden vor der Verfügbarkeitsprüfung bzw. dem Verbrauch einer Verschiebungsberechtigung abgelehnt; keine Teilmutation und verifiziertes Fixture-Cleanup |
| Optionaler WebMCP-Pilot: Auswahl, Zeitzone, Rennen und Abbruch | `tests/JavaScript/booking_webmcp.test.js` (Tests ab `find_available_slots...`, `booking preparation aborts...`) | JavaScript-Verhaltens-Harness; veraltete Antworten, sichtbarer State und Zeitzonen-/Fenstergrenzen |
| Fallback bei keinen Slots | `tests/Unit/Views/BookingTimeStepViewTest.php` | View-Verhalten; Fallback-Markup nur bei korrektem Feature-Flag |

## Buchung anlegen

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Normale Buchung erzeugt Kunde/Termin und Hash | `tests/Integration/Controllers/BookingControllerFlowTest.php::testRegisterSuccessCreatesAppointmentAndReturnsHash` | Verhaltens-Integration mit Datenbank; Persistenz, Zuordnung und Rückgabe-Identität |
| Register-Aufruf mit GET statt POST | `tests/Integration/Controllers/BookingMethodHttpTest.php::testGetRegisterRejectsValidPublicQueryPayloadWithoutMutation` | FH_DEFENSE_ISOLATED HTTP/DB; 405 und `Allow: POST` vor Query-Auswertung, keine Termin-/Kunden-/Consent-Mutation, Fixture-Cleanup |
| Späte Überschneidung/Identitäts-Lock | dieselbe Datei: `testCreationIdentityLockSerializesAcrossDatabaseConnections`, `testNormalCreationResolvesCustomerAfterIdentityLockAndRejectsLateOverlap` | Verhaltens-Integration; Doppelbuchung bzw. Race nach Verfügbarkeitsprüfung |
| DTO-Normalisierung der Register-Daten | `tests/Unit/Libraries/BookingRequestDtoFactoryTest.php` | Unit; verschachtelte Nutzdaten, optionale Kundenfelder und Kompatibilitätswerte |
| CAPTCHA ohne Sitzungs-Challenge bzw. mit falscher Eingabe | `tests/Integration/Controllers/BookingCaptchaHttpTest.php`, `BookingControllerFlowTest::testCaptchaRejectionDoesNotMutateOrConsumeValidAuthority`, `::testEmptyCaptchaPhraseAndInputRejectBeforeMutationOrAuthorityConsumption` | FH_DEFENSE_ISOLATED HTTP/DB belegt fehlende/leere Eingabe ohne Challenge, falsche Eingabe nach Bildabruf und die eigene erfolgreiche Buchung samt Consent mit gültiger Challenge. Abgelehnte Requests verändern Termin, Kunde, Consent und Authority nicht; der Controller-Test deckt auch eine ausdrücklich leere Sitzungsphrase ab. Kein Browser-Usability- oder aktueller Produktionskonfigurationsnachweis. |
| Fehlende Verfügbarkeit ohne Mutation | `BookingControllerFlowTest::testRegisterReturnsErrorWhenDateTimeUnavailable` | Verhaltens-Integration; keine Buchung bei fehlendem Slot |

## Bestätigung

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Browser lädt Bestätigungs-PDF und Download wird ausgewertet | `scripts/release-gate/booking_confirmation_pdf_gate.php` plus `scripts/release-gate/playwright/booking_confirmation_download.js` | Browser-/Release-Gate, read-only; reale Bestätigungsseite, PDF/Download und Parser |
| Externe Kalenderlinks enthalten Terminangaben, aber keine Verwaltungsberechtigung oder Teilnehmeradressen | `tests/Integration/Controllers/BookingDownloadHttpTest.php::testExternalCalendarLinksKeepEventDataWithoutCapabilityOrAttendeeDisclosure` | FH_DEFENSE_ISOLATED HTTP/DB; eigene Buchung über echten Bestätigungs-HTTP-Pfad, dekodierte Google-/Outlook-URLs und Fixture-Cleanup; keine Aussage über Speicherung oder Verhalten der Kalenderanbieter |
| Bestätigung bewahrt gemischte Script-Tags, Sonderzeichen und Unicode in Share-/PDF-Daten | `tests/Integration/Controllers/BookingDownloadHttpTest.php::testConfirmationJsonRoundTripsOwnedNamesWithoutScriptBreakout` | FH_DEFENSE_ISOLATED HTTP/DB; echte Fixture, eigener Hash, Status/Payload, Button-/Link-Erreichbarkeit, JSON-Roundtrip und kein ausführbarer Script-Ausbruch; Fixture-Cleanup |
| Download-Sentinel bleibt stabil | `tests/Unit/Scripts/BookingConfirmationDownloadSnippetTest.php`, `BookingConfirmationRunCodeResultTest.php` | Source-/Parser-Unit; Marker, Fallback-Ausgabe und ungültige Playwright-Ausgabe |

| Kalenderdatei und Download | `tests/Unit/Libraries/IcsFileTest.php` plus `Appointments::ics` coverage | Unit/HTTP; ICS generation and parent download remain available; application mail is intentionally absent |

## Verwalten, verschieben, stornieren

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Reschedule-Link öffnet Manage-Modus bzw. sperrt zu kurze Vorläufe | `BookingControllerFlowTest::testRescheduleSetsManageModeForValidHash`, `...::testRescheduleShowsLockedMessageWhenInsideAdvanceTimeout` | Verhaltens-Integration; falscher Kontext oder unzulässige kurzfristige Änderung |
| Nicht-GET-Aufruf des Reschedule-Links ersetzt keine Berechtigung | `tests/Integration/Controllers/RescheduleMethodHttpTest.php` | FH_DEFENSE_ISOLATED HTTP/DB mit zwei Sitzungen; gültiger GET vergibt Authority, HEAD/POST auf Rewrite-/Direktpfad erhalten 405/`Allow: GET` ohne Authority- oder Cache-Änderung, eigenes Fixture-Cleanup |
| Reschedule-Schreibrechte, Fremd-IDs, Ablauf, Replay und Drift | `BookingControllerFlowTest` (Authority-Tests ab `testForgedManageModeWithoutAuthorityRejectsWithoutMutation`) | Verhaltens-Integration; keine Mutation ohne serverseitige Authority |
| Falsche HTTP-Methode bei bereits autorisierter Umbuchung | `BookingMethodHttpTest::testGetRegisterRejectsSessionAuthorizedReschedulePayloadWithoutMutation` | FH_DEFENSE_ISOLATED HTTP/DB; GET 405 ohne Mutation oder Verbrauch der Einmal-Authority, anschließend gültiger POST-Kontrollpfad und Fixture-Cleanup |
| Stornieren mit gültigem oder unbekanntem Hash | `tests/Integration/Controllers/BookingCancellationControllerFlowTest.php` | Verhaltens-Integration; Löschung/Status und Not-found-Schutz |
| Gesamter öffentlicher Schreibvertrag | `scripts/ci/booking_write_contract_smoke.php` (inkl. Register, Reschedule, Cancel; Auswahl in `scripts/ci/run_deep_runtime_suite.php`) | HTTP-Contract-Smoke; verdrahtete Endpunkte und reale Antwortverträge |

| Kunde oder Angebot löschen | `tests/Unit/Models/ParentCascadeBufferCleanupTest.php` | Datenbanktest; eigene Termine und Puffer verschwinden, fremde bleiben erhalten |

## Verwaltungskalender

| Verhalten | Tests | Abgedecktes Risiko |
| --- | --- | --- |
| Anbieter-Konfiguration in Kalenderseite und Ereignisantworten | `tests/Integration/Controllers/CalendarProviderDataTest.php` | Arbeitspläne bleiben verfügbar; historische Integrationsgeheimnisse werden nicht an den Browser weitergegeben. |
| Sperrzeiten mit rollenabhängigen Notizen | `tests/Integration/Controllers/CalendarEventPermissionsTest.php` | Kalendernutzer sehen gesperrte Zeiten; zusätzliche Notizen erfordern Leserecht für Sperrzeiten. |

## Entscheidung zur Vereinfachung

In `ParentCascadeBufferCleanupTest` genügt je ein Szenario für Kunde und
Angebot. Es prüft sowohl die Löschung als auch den Schutz anderer Buchungen.
Die bisherigen separaten Löschtests wiederholten denselben Modellaufruf und
dieselben Löschbehauptungen; ihre Vorher-Prüfungen bleiben im gemeinsamen
Szenario erhalten. Vier Tests werden zu zwei, ohne Änderung am Produktcode.

## Überschneidungen und belegte Grenzen

- `BookingControllerFlowTest` und `booking_write_contract_smoke.php` decken
  teilweise dieselben Schreibverträge ab. Der erste ist DB-nahe PHPUnit-
  Integration, der zweite HTTP-/Runtime-Smoke; eine Entfernung wäre daher nur
  nach bewusstem Austausch der jeweiligen Ebene vertretbar.
- `BookingReadAvailabilityControllerFlowTest` und die JavaScript-Harness prüfen
  beide Verfügbarkeit, aber die PHP-Suite die Controller-Antwort und die JS-
  Suite sichtbaren Browser-State, Abbruch und Rennen.
- Die hier zugeordneten Tests zeigen keinen vollständigen realen Browser-Checkout
  mit Kontaktdateneingabe und Bestätigung; der WebMCP-Test endet ausdrücklich
  bei `prepare_booking`, der PDF-Gate-Fluss startet mit bereits vorhandenem
  Bestätigungs-Hash. Das ist eine beobachtete Abgrenzung, kein Vorschlag für
  neue Tests.
- Der bisherige `BookingConfirmationJsonTest` prüfte nur ausgewählte
  `json_encode`-Ausdrücke im View-Quelltext. Der HTTP-Test ersetzt ihn durch
  gerenderte Share- und PDF-Daten aus einer eigenen DB-Fixture; er schützt
  damit das Ausgabeverhalten auch bei einer anderen sicheren Kodierung.
