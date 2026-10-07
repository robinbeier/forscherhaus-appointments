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
| Manuelle Sperrzeit eines Anbieters stimmt mit öffentlicher Verfügbarkeit und Direktbuchung überein | `tests/Integration/Controllers/BookingManualUnavailabilityHttpTest.php`; `scripts/ci/calendar_unavailability_dialog_browser.js` via Integration-Smoke | FH_DEFENSE_ISOLATED HTTP/DB: Ein eigener Anbieter speichert die Sperrzeit über den authentifizierten Kalender-POST. Der zuvor freie Slot verschwindet aus der öffentlichen Anzeige, ein angrenzender freier Slot bleibt sichtbar, und der direkte Buchungs-POST wird mit 409 ohne Kunden-, Termin- oder Consent-Teiländerung abgelehnt. Nach autorisiertem Kalender-Löschen ist der Slot wieder frei und die positive Kontrollbuchung gelingt; alle eigenen Testdaten werden bereinigt. Der Browser-Smoke prüft zusätzlich Dialog, Anfrageinhalt, Fehlerzustand und Erfolgsreaktion mit abgefangenen Speicheranfragen ohne Datenbankänderung. Beides zusammen belegt den lokalen Ablauf, keinen gleichzeitigen Schreibvorgang oder produktiven Live-Test. |
| Manuelle Sperrzeit eines Anbieters bei autorisierter öffentlicher Umbuchung | `tests/Integration/Controllers/BookingManualUnavailabilityRescheduleHttpTest.php` | FH_DEFENSE_ISOLATED HTTP/DB mit eigenem Termin und sitzungsgebundener Einmalberechtigung: Die manuelle Sperre entfernt den Zielslot aus der Anzeige; der direkte Umbuchungs-POST wird mit 409 ohne Termin-, Kunden-, Service- oder Consent-Teiländerung abgelehnt. Der abgewiesene Versuch verbraucht die Berechtigung. Nach Entfernen der eigenen Sperre und neuer Berechtigung gelingt die Umbuchung. Belegt diesen lokalen Ablauf, keinen gleichzeitigen Sperrzeit-Edit oder Live-Test. |
| Neue globale Sperrzeit während eines öffentlichen Buchungs-POSTs | `tests/Integration/Controllers/PublicBookingBlockedPeriodRaceHttpTest.php` | Isolierte HTTP-/DB-Integration: Die Buchung wartet nach ihrer ersten Verfügbarkeitsprüfung am Provider-Lock. Eine zweite Verbindung speichert und bestätigt die eigene Sperrzeit, bevor die Buchung weiterläuft. Der letzte Verfügbarkeitsentscheid lehnt den Slot mit 409 ohne Kunden-, Termin- oder Consent-Teiländerung ab. Nach Entfernung der eigenen Sperrzeit gelingt die positive Kontrollbuchung. Das belegt genau diesen Schedule, nicht jede mögliche zeitliche Überlappung oder Produktion. |
| Neue globale Sperrzeit während eines öffentlichen Umbuchungs-POSTs | `tests/Integration/Controllers/PublicRescheduleBlockedPeriodRaceHttpTest.php` | Isolierte HTTP-/DB-Integration mit eigener Sitzung und eigenem Termin: Der POST wartet am Provider-Lock; eine zweite Verbindung bestätigt die Sperrzeit vor seiner Fortsetzung. Der POST antwortet mit 409, ohne Termin-, Kunden-, Service- oder Consent-Teiländerung; die Einmalberechtigung ist verbraucht. Nach Entfernung der eigenen Sperrzeit und Ausstellung einer neuen Berechtigung gelingt die positive Kontrollumbuchung. Das belegt diesen geordneten Ablauf, nicht alle möglichen Überschneidungen oder einen Produktionslauf. |
| Neue manuelle Anbieter-Sperrzeit während eines öffentlichen Buchungs-POSTs | `tests/Integration/Controllers/PublicBookingManualUnavailabilityRaceHttpTest.php` | Isolierte HTTP-/DB-Integration: Eine zweite Verbindung hält die eigene manuelle Sperrzeit bis nach der ersten Verfügbarkeitsprüfung der Buchung unbestätigt; der POST wartet am Provider-Lock. Nach dem Commit verifiziert eine unabhängige Verbindung den Block, und die Buchung antwortet mit 409 ohne Kunden-, Termin- oder Consent-Teiländerung. Eine getrennte lokale Kontrolle bucht den Slot nach Entfernen der eigenen Sperre. Belegt genau diesen geordneten Ablauf; weder den Calendar-/API-HTTP-Schreibweg noch alle Überschneidungen oder Produktion. |
| Neue manuelle Anbieter-Sperrzeit während eines autorisierten öffentlichen Umbuchungs-POSTs | `tests/Integration/Controllers/PublicRescheduleManualUnavailabilityRaceHttpTest.php` | Isolierte HTTP-/DB-Integration mit eigenem Termin und sitzungsgebundener Einmalberechtigung: Eine zweite Verbindung hält eine eigene manuelle Sperrzeile bis nach der ersten Verfügbarkeitsprüfung unbestätigt; der POST wartet am Provider-Lock. Die Sperre wird bestätigt und unabhängig nachgelesen; der POST antwortet mit 409 ohne Termin-, Kunden-, Service- oder Consent-Teiländerung und verbraucht die Berechtigung. Das Nachlesen kann nach der Fortsetzung des POSTs erfolgen. Nach Entfernen des eigenen Blocks gelingt die positive Kontrolle mit neuer Berechtigung. Belegt einen geordneten Ablauf, nicht Calendar-/API-HTTP-Schreibwege, alle Überschneidungen oder Produktion. |
| Optionaler WebMCP-Pilot: Auswahl, Zeitzone, Rennen und Abbruch | `tests/JavaScript/booking_webmcp.test.js` (Tests ab `find_available_slots...`, `booking preparation aborts...`) | JavaScript-Verhaltens-Harness; veraltete Antworten, sichtbarer State und Zeitzonen-/Fenstergrenzen |
| Fallback bei keinen Slots | `tests/Unit/Views/BookingTimeStepViewTest.php` | View-Verhalten; Fallback-Markup nur bei korrektem Feature-Flag |

## Buchung anlegen

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Normale Buchung erzeugt Kunde/Termin und Hash | `tests/Integration/Controllers/BookingControllerFlowTest.php::testRegisterSuccessCreatesAppointmentAndReturnsHash` | Verhaltens-Integration mit Datenbank; Persistenz, Zuordnung und Rückgabe-Identität |
| Register-Aufruf mit GET statt POST | `tests/Integration/Controllers/BookingMethodHttpTest.php::testGetRegisterRejectsValidPublicQueryPayloadWithoutMutation` | FH_DEFENSE_ISOLATED HTTP/DB; 405 und `Allow: POST` vor Query-Auswertung, keine Termin-/Kunden-/Consent-Mutation, Fixture-Cleanup |
| Identitäts-Lock und späte Kundenkollision | dieselbe Datei: `testCreationIdentityLockSerializesAcrossDatabaseConnections`, `testNormalCreationResolvesCustomerAfterIdentityLockAndRejectsLateOverlap` | Verhaltens-Integration; gleiche Kundenidentität und erneute Kundenüberschneidung nach dem Identity-Lock, kein paralleler HTTP-Buchungsschedule |
| Zwei gleichzeitige öffentliche Buchungen desselben Anbieter-Slots | `tests/Integration/Controllers/PublicBookingProviderRaceHttpTest.php` | Isolierte HTTP-/DB-Integration; beide Requests warten nach der ersten Verfügbarkeitsprüfung am tatsächlichen Provider-Lock, danach ein Erfolg und ein 409 ohne Teiländerung des Verlierers. Belegt genau diesen Schedule, keine beliebigen konkurrierenden Kalenderänderungen oder Produktion unter Last. |
| DTO-Normalisierung der Register-Daten | `tests/Unit/Libraries/BookingRequestDtoFactoryTest.php` | Unit; verschachtelte Nutzdaten, optionale Kundenfelder und Kompatibilitätswerte |
| CAPTCHA-Challenge, Wiederverwendung und Neubeginn | `tests/Integration/Controllers/BookingCaptchaHttpTest.php`, `tests/JavaScript/booking_webmcp.test.js`, `BookingControllerFlowTest::testCaptchaRejectionDoesNotMutateOrConsumeValidAuthority`, `::testEmptyCaptchaPhraseAndInputRejectBeforeMutationOrAuthorityConsumption` | FH_DEFENSE_ISOLATED HTTP/DB belegt fehlende/leere Eingabe ohne Challenge, falsche Eingabe nach Bildabruf, erfolgreiche eigene Buchung mit gültiger Challenge, Ablehnung einer zweiten Buchung mit derselben Phrase ohne Teiländerung und erfolgreiche Buchung des weiterhin freien Slots mit neuem Bild. Ein weiterer HTTP-Test belegt, dass auch nach einem späteren 409 die alte Phrase verbraucht, der Datensatz unverändert und der Retry mit neuer Phrase möglich ist. Der JavaScript-Test belegt das Leeren des alten Eingabewerts beim Bildwechsel. Der Controller-Test deckt auch eine ausdrücklich leere Sitzungsphrase ab. Kein vollständiger Browser-Usability- oder aktueller Produktionskonfigurationsnachweis. |
| Fehlende Verfügbarkeit ohne Mutation | `BookingControllerFlowTest::testRegisterReturnsErrorWhenDateTimeUnavailable` | Verhaltens-Integration; keine Buchung bei fehlendem Slot |

## Bestätigung

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Browser lädt Bestätigungs-PDF und Download wird ausgewertet | `scripts/release-gate/booking_confirmation_pdf_gate.php` plus `scripts/release-gate/playwright/booking_confirmation_download.js` | Browser-/Release-Gate, read-only; reale Bestätigungsseite, PDF/Download und Parser |
| Externe Kalenderlinks enthalten Terminangaben, aber keine Verwaltungsberechtigung oder Teilnehmeradressen | `tests/Integration/Controllers/BookingDownloadHttpTest.php::testExternalCalendarLinksKeepEventDataWithoutCapabilityOrAttendeeDisclosure` | FH_DEFENSE_ISOLATED HTTP/DB; eigene Buchung über echten Bestätigungs-HTTP-Pfad, dekodierte Google-/Outlook-URLs und Fixture-Cleanup; keine Aussage über Speicherung oder Verhalten der Kalenderanbieter |
| Bestätigung bewahrt gemischte Script-Tags, Sonderzeichen und Unicode in Share-/PDF-Daten | `tests/Integration/Controllers/BookingDownloadHttpTest.php::testConfirmationJsonRoundTripsOwnedNamesWithoutScriptBreakout` | FH_DEFENSE_ISOLATED HTTP/DB; echte Fixture, eigener Hash, Status/Payload, Button-/Link-Erreichbarkeit, JSON-Roundtrip und kein ausführbarer Script-Ausbruch; Fixture-Cleanup |
| Download-Sentinel bleibt stabil | `tests/Unit/Scripts/BookingConfirmationDownloadSnippetTest.php`, `BookingConfirmationRunCodeResultTest.php` | Source-/Parser-Unit; Marker, Fallback-Ausgabe und ungültige Playwright-Ausgabe |

| Kalenderdatei und Download | `tests/Unit/Libraries/IcsFileTest.php` plus `BookingDownloadHttpTest.php` | Unit/isoliertes HTTP mit eigenen Datensätzen; aktuelle und künftige moderne/alte Links bleiben nutzbar, beendete Termine liefern keine Bestätigungs- oder ICS-Daten, und ICS enthält keine Eltern- oder Anbieter-E-Mail-Adresse. Zwei lokale Display-Erinnerungen bleiben erhalten; kein Nachweis für externes Kalenderverhalten. |

## Verwalten, verschieben, stornieren

| Reise/Verhalten | Tests | Ebene und abgedecktes Risiko |
| --- | --- | --- |
| Reschedule-Link öffnet Manage-Modus bzw. sperrt zu kurze Vorläufe | `BookingControllerFlowTest::testRescheduleSetsManageModeForValidHash`, `...::testRescheduleShowsLockedMessageWhenInsideAdvanceTimeout` | Verhaltens-Integration; falscher Kontext oder unzulässige kurzfristige Änderung |
| Nicht-GET-Aufruf des Reschedule-Links ersetzt keine Berechtigung | `tests/Integration/Controllers/RescheduleMethodHttpTest.php` | FH_DEFENSE_ISOLATED HTTP/DB mit zwei Sitzungen; gültiger GET vergibt Authority, HEAD/POST auf Rewrite-/Direktpfad erhalten 405/`Allow: GET` ohne Authority- oder Cache-Änderung, eigenes Fixture-Cleanup |
| Umbuchungsseite gibt nur benötigte Terminfelder aus | `RescheduleMethodHttpTest::testGetRescheduleProjectsOnlyThePublicAppointmentFields` | FH_DEFENSE_ISOLATED HTTP/DB; gerenderte HTTP-Antwort enthält sechs öffentliche Terminfelder, aber keine internen Kalenderkennungen; eigener Hash, Customer-Token und Authority bleiben gebunden, Termin unverändert und synthetische Daten bereinigt |
| Reschedule-Schreibrechte, Fremd-IDs, Ablauf, Replay und Drift | `BookingControllerFlowTest` (Authority-Tests ab `testForgedManageModeWithoutAuthorityRejectsWithoutMutation`) | Verhaltens-Integration; keine Mutation ohne serverseitige Authority |
| Falsche HTTP-Methode bei bereits autorisierter Umbuchung | `BookingMethodHttpTest::testGetRegisterRejectsSessionAuthorizedReschedulePayloadWithoutMutation` | FH_DEFENSE_ISOLATED HTTP/DB; GET 405 ohne Mutation oder Verbrauch der Einmal-Authority, anschließend gültiger POST-Kontrollpfad und Fixture-Cleanup |
| Stornieren mit gültigem, gesperrtem, unbekanntem oder mehrdeutigem Hash | `tests/Integration/Controllers/BookingCancellationControllerFlowTest.php`, `BookingCancellationHttpTest.php`, `BookingCancellationRaceHttpTest.php` | Controller- und isolierte HTTP-/DB-Integration; POST-Grenze, Frist, Löschung, Replay eines verbrauchten Hashes ohne weitere Änderung, generische Ablehnung eines doppelten Hashs ohne Termin-/Puffer-/Kundenänderung, Rollback bei Fehler sowie konkurrierende Hash-/Friständerung. Keine Aussage über produktive Hash-Kollisionen oder gleichzeitig neu eingefügte Duplikate. |
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
