# Buchungs-Testkarte

Diese Karte ordnet die vorhandenen Tests nach Nutzerreise und Testebene. Sie
ist eine Orientierung, keine vollständige Testliste.

## Termin finden

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Buchungsseite lädt Dienste/Anbieter | `tests/Integration/Controllers/BookingReadAvailabilityControllerFlowTest.php::testIndexExposesBookingBootstrapForPublicFlow` | Verhaltens-Integration; fehlender öffentlicher Bootstrap bzw. versehentlich aktivierter Manage-Modus |
| Verfügbare Stunden (konkreter oder beliebiger Anbieter) | dieselbe Datei: `testGetAvailableHoursReturnsArrayForSpecificProvider`, `testGetAvailableHoursSupportsAnyProviderSentinel` | Verhaltens-Integration; Antwortform und Any-Provider-Kompatibilität |
| Nicht verfügbare Tage | `.../BookingReadAvailabilityControllerFlowTest.php::testGetUnavailableDatesReturnsArrayOrMonthUnavailableFlag` | Verhaltens-Integration; Datumsformat oder Monatsstatus |
| Optionaler WebMCP-Pilot: Auswahl, Zeitzone, Rennen und Abbruch | `tests/JavaScript/booking_webmcp.test.js` (Tests ab `find_available_slots...`, `booking preparation aborts...`) | JavaScript-Verhaltens-Harness; veraltete Antworten, sichtbarer State und Zeitzonen-/Fenstergrenzen |
| Fallback bei keinen Slots | `tests/Unit/Views/BookingTimeStepViewTest.php` | View-Verhalten; Fallback-Markup nur bei korrektem Feature-Flag |

## Buchung anlegen

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Normale Buchung erzeugt Kunde/Termin und Hash | `tests/Integration/Controllers/BookingControllerFlowTest.php::testRegisterSuccessCreatesAppointmentAndReturnsHash` | Verhaltens-Integration mit Datenbank; Persistenz, Zuordnung und Rückgabe-Identität |
| Späte Überschneidung/Identitäts-Lock | dieselbe Datei: `testCreationIdentityLockSerializesAcrossDatabaseConnections`, `testNormalCreationResolvesCustomerAfterIdentityLockAndRejectsLateOverlap` | Verhaltens-Integration; Doppelbuchung bzw. Race nach Verfügbarkeitsprüfung |
| DTO-Normalisierung der Register-Daten | `tests/Unit/Libraries/BookingRequestDtoFactoryTest.php` | Unit; verschachtelte Nutzdaten, optionale Kundenfelder und Kompatibilitätswerte |
| CAPTCHA/fehlende Verfügbarkeit ohne Mutation | `BookingControllerFlowTest` (u. a. `testRegisterReturnsErrorWhenDateTimeUnavailable`, `testCaptchaRejectionDoesNotMutateOrConsumeValidAuthority`) | Verhaltens-Integration; fail-closed Schreibpfad |

## Bestätigung

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Browser lädt Bestätigungs-PDF und Download wird ausgewertet | `scripts/release-gate/booking_confirmation_pdf_gate.php` plus `scripts/release-gate/playwright/booking_confirmation_download.js` | Browser-/Release-Gate, read-only; reale Bestätigungsseite, PDF/Download und Parser |
| Download-Sentinel bleibt stabil | `tests/Unit/Scripts/BookingConfirmationDownloadSnippetTest.php`, `BookingConfirmationRunCodeResultTest.php` | Source-/Parser-Unit; Marker, Fallback-Ausgabe und ungültige Playwright-Ausgabe |

| Mail-Konfiguration und Kalenderdatei | `tests/Unit/Libraries/EmailMessagesTest.php`, `IcsFileTest.php` | Unit; SMTP-/HTML-Konfiguration und Verwaltungslink; kein Nachweis einer Mailzustellung |

## Verwalten, verschieben, stornieren

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Reschedule-Link öffnet Manage-Modus bzw. sperrt zu kurze Vorläufe | `BookingControllerFlowTest::testRescheduleSetsManageModeForValidHash`, `...::testRescheduleShowsLockedMessageWhenInsideAdvanceTimeout` | Verhaltens-Integration; falscher Kontext oder unzulässige kurzfristige Änderung |
| Reschedule-Schreibrechte, Fremd-IDs, Ablauf, Replay und Drift | `BookingControllerFlowTest` (Authority-Tests ab `testForgedManageModeWithoutAuthorityRejectsWithoutMutation`) | Verhaltens-Integration; keine Mutation ohne serverseitige Authority |
| Stornieren mit gültigem oder unbekanntem Hash | `tests/Integration/Controllers/BookingCancellationControllerFlowTest.php` | Verhaltens-Integration; Löschung/Status und Not-found-Schutz |
| Gesamter öffentlicher Schreibvertrag | `scripts/ci/booking_write_contract_smoke.php` (inkl. Register, Reschedule, Cancel; Auswahl in `scripts/ci/run_deep_runtime_suite.php`) | HTTP-Contract-Smoke; verdrahtete Endpunkte und reale Antwortverträge |

| Kunde oder Angebot löschen | `tests/Unit/Models/ParentCascadeBufferCleanupTest.php` | Datenbanktest; eigene Termine und Puffer verschwinden, fremde bleiben erhalten |

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
