# PLAN: Balanced Desktop Layout for Booking Confirmation

## Ausgangsanalyse

-   Desktop-Wrapper nutzt bereits `max-width: 1120px`, aber die Außenabstände bleiben bei 16px (`padding: 0 1rem`) und liegen damit unter den geforderten 24–40px (`application/views/pages/booking_confirmation.php:5-11`).
-   Der Aufbau ist aktuell gestapelt: Banner fehlt komplett, die Terminübersicht liegt in einem separaten Card-Block, und Manage-/Kalender-Karten folgen darunter (`application/views/pages/booking_confirmation.php:501-585`). Das entspricht nicht dem gewünschten zweispaltigen „Balanced Split“.
-   Desktop-Gitterspalten sind als `repeat(2, minmax(260px, 1fr))` bzw. `repeat(2, minmax(0, 1fr))` definiert und verfehlen die Zielbreite `minmax(440, 560) | minmax(480, 640)` (`application/views/pages/booking_confirmation.php:410-425`).
-   Der aktuelle Spaltengap beträgt 1.5rem (24px) und liegt damit unter dem Zielkorridor 40–56px (`application/views/pages/booking_confirmation.php:421-424`).
-   Mobile- und Tablet-Breakpoints sind bereits separat gepflegt (≤959px) und sollen unverändert bleiben (`application/views/pages/booking_confirmation.php:264-360`).

## Sollbild Desktop (≥960px)

-   Kopfbereich als vollbreiter Banner über beiden Spalten mit Titel „✅ Termin gebucht“ plus kurzem Bestätigungssatz.
-   Neues Desktop-Gitter mit zwei Spalten (links: Termindetails + Änderungs-/Storno-CTA, rechts: Hinweis, Share-/Copy-Utilities und Kalender-CTA), gleich große Außen-Gutter, visuell zentriert.
-   Spaltenbreiten per `grid-template-columns: minmax(440px, 560px) minmax(480px, 640px)` (oder equivalente `clamp`-Variante), Gap im Bereich 48px (anpassbar zwischen 40–56px).
-   Außenabstand des Wrappers per `padding-inline` im Bereich 24–40px, unabhängig vom Viewport symmetrisch.
-   Mobile/Tablet-Layout beibehaltung: CSS-Neuerungen nur in Desktop-Media-Query.

## ToDos

-   [x] Markup umstrukturieren: neuen Container für den Desktop-Split anlegen, der Terminübersicht und Manage-CTA in einer linken Spalte vereint sowie Hinweis-/Utility-Block + Kalender in der rechten Spalte kapselt.
-   [x] Top-Banner-Komponente ergänzen (inkl. kurzem Text) und sicherstellen, dass Kopie weiterhin über vorhandene Übersetzungsstrings gepflegt wird.
-   [x] CSS für Desktop aktualisieren: neue Grid-Klassen, Spaltenbreiten, Gap und Wrapper-Gutter; darauf achten, bestehende mobile Styles nicht zu brechen.
-   [x] Utilities (Copy/Share, Kalender) so positionieren, dass Interaktionen und Collapse-Logik unverändert funktionieren; Fokuszustände prüfen.
-   [x] Falls neue Texte notwendig sind, passende Lang-Fallbacks ergänzen. (Bestehende Strings wiederverwendet.)
-   [ ] Regressionstests: `composer test`, manuelle QA bei 960px, 1200px sowie kurzer Smoke-Test mobil, um sicherzustellen, dass keine Seiteneffekte entstanden sind.

## Antworten auf Rückfragen

-   Banner-Untertitel nutzt den bestehenden String `$lang['appointment_registered'] = 'Ihr Termin ist erfolgreich registriert worden.';`
-   CTA-Button behält die bestehende Stilistik ohne zusätzliche Hervorhebungen.
