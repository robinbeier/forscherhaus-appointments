# Provider-Raum (`room`) – Plan & To-dos

## Ziel & Definition of Done
**Ziel:**  
- Admins können im Backend jedem Anbieter (Lehrkraft) ein neues Textfeld **„Raum“** pflegen.  
- Eltern sehen den Raum:  
  - im **Schritt 4** des Buchungs-Wizards (Zusammenfassung),  
  - und auf der **Buchungsbestätigungsseite** inkl. dem Link **„Zum Google-Kalender hinzufügen“** (der Raum soll in der Location landen).  
**DoD:** Neues Feld ist speicherbar, erscheint korrekt im Wizard-Schritt 4 und in der Bestätigung; Google-Kalender-Link enthält den Raum in `location`.

## Technischer Überblick (Dateien/Stellen)
- **DB:** Spalte `users.room` (VARCHAR(64)).
- **Backend Whitelists:**  
  - `application/controllers/Providers.php` enthält `allowed_provider_fields` – hier `room` ergänzen. 
  - `application/models/Providers_model.php` enthält `$api_resource`-Mapping (API-Feldnamen ↔ DB-Felder) – hier `room` ergänzen; außerdem wird `get_available_providers()` genutzt, dessen Ergebnis im Wizard ankommt.  
- **Booking-Seite (Wizard) Controller:**  
  - `application/controllers/Booking.php` whitelisted `allowed_provider_fields` (aktuell u.a. `id, first_name, last_name, services, timezone`) und füllt `available_providers` für `script_vars` – hier `room` ergänzen, damit das Frontend es bekommt.  
- **Frontend:**  
  - `assets/js/pages/booking.js` aktualisiert die Zusammenfassung des Wizards in Schritt 4 – hier „Raum“ anzeigen. (Datei ist Teil des Projekts, siehe Commit-Diff-Listing mit `booking.js`.)  
- **Buchungsbestätigung & Google-Link:**  
  - `application/controllers/Booking_confirmation.php` lädt die Bestätigungsseite und baut `add_to_google_url` über `google_sync->get_add_to_google_url($appointment['id'])`. Hier den **Raum an die View** geben; im **Google-Link** muss `location` den Raum beinhalten.

## Risiken & Rückfallplan
- Falls die Migration nicht greift: SQL direkt ausführen und Eintrag im Plan abhaken.
- Falls sich Markup im Wizard unterscheidet: Raum an passender Stelle der Zusammenfassung einfügen (funktional wichtig ist nur, **dass** er erscheint).
- Bei Problemen mit dem Google-Link: Raum an den bestehenden `location`-String **anhängen** („{bisheriger Ort}; Raum {room}“).

## Tasks (To-dos)

### T1 – Branch
- ✅ Branch `feature/provider-room` anlegen.
- ✅ **Akzeptanz:** `git branch --show-current` → `feature/provider-room`.

### T2 – DB: Spalte `users.room`
- ✅ Migration anlegen oder direktes SQL ausführen:  
  `ALTER TABLE users ADD COLUMN room VARCHAR(64) NULL AFTER notes;`
- ✅ **Akzeptanz:** `SHOW COLUMNS FROM users LIKE 'room';` zeigt Spalte.

### T3 – Backend-Whitelists öffnen für `room`
- ✅ `application/controllers/Providers.php`: `allowed_provider_fields` → `room` hinzufügen.
- ✅ `application/models/Providers_model.php`: in `$api_resource` Mapping "room" => "room" ergänzen.
- ✅ **Akzeptanz:** Provider-CRUD via Admin-UI funktioniert weiterhin (Speichern/Lesen ohne Fehler).

### T4 – Backend-UI: Feld „Raum“ bei Lehrkraft
- ✅ `application/views/pages/providers.php` (Formular) um Textfeld **Raum** erweitern (Label, Input, Helfertext).
- ✅ Falls nötig `assets/js/pages/providers.js` um Serialisierung von `room` ergänzen.
- ✅ **Akzeptanz:** In der Admin-Maske einer Lehrkraft ist das Feld sichtbar, Speichern ändert DB-Wert.

### T5 – Booking Controller liefert `room` ins Frontend
- ✅ `application/controllers/Booking.php`: `allowed_provider_fields` um `room` erweitern, damit `available_providers` → `room` enthält (siehe `script_vars([... 'available_providers' => ...])`). 
- ✅ **Akzeptanz:** Netzwerk-Tab im Browser (Buchungsseite laden), `script_vars` prüfen: Provider-Objekte enthalten `room`.

### T6 – Wizard Schritt 4: Raum anzeigen
- ✅ `assets/js/pages/booking.js`: an der Stelle, an der die Schritt-4-Zusammenfassung aufgebaut/aktualisiert wird, **Zeile „Raum: …“** ergänzen (`selectedProvider.room`).
- ✅ **Akzeptanz:** Im Wizard (Schritt 4) erscheint „Raum: XYZ“ sobald ein Provider feststeht.

### T7 – Buchungsbestätigung: Raum + Google-Link
- ✅ `application/controllers/Booking_confirmation.php`:  
  - Neben `add_to_google_url` auch den **Raum** der View übergeben (Provider per `providers_model->find($appointment['id_users_provider'])`).  
- ✅ **Google-Link**: in `application/libraries/Google_sync.php` (Methode `get_add_to_google_url`) `location` um `; Raum {room}` erweitern.  
- ✅ `application/views/pages/booking_confirmation.php`: Raum prominent anzeigen.
- ✅ **Akzeptanz:** Klick auf „Zum Google-Kalender hinzufügen“ erstellt Termin mit Location inkl. Raum.

### T8 – Übersetzungen
- ✅ In `application/language/*/translations_lang.php` Schlüssel `room`/`Raum` ergänzen (DE/EN mind.).  
- ✅ **Akzeptanz:** Labels erscheinen lokalisiert.

### T9 – Regressionscheck & Doku
- ✅ Buchung mit „beliebiger Anbieter“ → Datenfluss geprüft (Wizard erhält `room`, Schritt 4 & Bestätigung zeigen den Wert nach Zuteilung).  
- ✅ README/CHANGELOG Eintrag ergänzt.

## Manuelle Tests (Kurzform)
1) **Admin**: Raum für Lehrkraft setzen → speichern → erneut öffnen: Wert bleibt.  
2) **Buchung**: Service/Lehrkraft wählen → Schritt 4: „Raum:“ sichtbar.  
3) **Bestätigung**: Raum sichtbar; Google-Kalender-Termin enthält Raum in Location.

## Rollout / Rollback
- Rollout: DB-Migration, Cache neu laden (falls aktiv), Frontend bauen (falls Buildprozess).  
- Rollback: Migration `down` (Spalte droppen), Code revert auf `main`.
