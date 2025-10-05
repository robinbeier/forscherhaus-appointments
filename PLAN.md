
# PLAN: EasyAppointments – Bestätigungsseite erweitern (Terminübersicht + smarte Kalender-Links)

**Ziel**  
Auf der Buchungsbestätigungsseite sollen (a) alle Termin-Informationen wie in Schritt 4 nochmals angezeigt werden und (b) neben dem bestehenden Google‑Kalender‑Button auch **Outlook** und **iOS/Apple Kalender (.ics)** als „smarte“ Links verfügbar sein.

---

## Kontext & Problem
Eltern erhalten nach der Buchung nur eine Erfolgsmeldung und einen Google‑Kalender‑Button. Der hilfreiche Hinweis _„Fügen Sie den Termin unten Ihrem Kalender hinzu oder notieren Sie sich Datum und Uhrzeit.“_ zeigt jedoch **keine** Daten an. Viele Eltern nutzen iPhones und Outlook/Microsoft‑Konten — deshalb benötigen wir drei verlässliche Add‑to‑Calendar‑Optionen und eine gut sichtbare, konsolidierte Terminübersicht.

---

## Anforderungen (funktional)

1. **Terminübersicht auf der Bestätigungsseite**
   - Zeige die gleichen Inhalte wie in „Schritt 4 – Bestätigung“ erneut an (Titel/Typ, Ansprechpartner, Ort/Raum, Datum, Startzeit, Dauer, Zeitzone).
   - Darstellung konsistent zu Schritt 4 (Icon‑Liste / kompakte Zusammenfassung).

2. **Drei Add‑to‑Calendar‑Aktionen**
   - **Google Kalender (Web):** vorbefüllter Link (`action=TEMPLATE`) mit Titel, Start/Ende (UTC oder TZ), Beschreibung, Ort, `ctz` (z. B. `Europe/Berlin`).
   - **Outlook (Microsoft 365 / Outlook.com):** Deeplink `outlook.office.com` (Fallback `outlook.live.com`) mit `rru=addevent`, `startdt`, `enddt`, `subject`, `body`, `location` (ISO‑8601).
   - **iOS / Apple Kalender:** Download‑Link auf eine **.ics**‑Datei (Einzeltermin), der in Safari/iOS direkt „Zum Kalender hinzufügen“ öffnet. (Die .ics ist universell und funktioniert auch mit Desktop‑Outlook, Apple Kalender und anderen.)

3. **Lokalisierung**
   - Neue Sprachschlüssel für Buttontexte, Abschnittstitel und Fehlermeldungen (DE, EN mindestens).

4. **Datenschutz / Sicherheit**
   - In URL‑Parametern nur notwendige Daten (keine sensiblen Notizen).  
   - .ics Download mit `Content-Type: text/calendar; charset=utf-8` und `Content-Disposition: attachment; filename="Termin.ics"`.

5. **Barrierefreiheit & UX**
   - Buttons nebeneinander, gleiche Gewichtung; Tastatur‑fokussierbar; klare Labels.
   - Bei Terminen in der Vergangenheit: Buttons deaktivieren/ausblenden.

---

## Nicht‑Ziele
- Kein Kalender‑Abonnement (webcal://) – nur Einzeltermin‑Download.
- Keine Änderung des E‑Mail‑Flows oder der 2‑Way‑Sync‑Funktion.

---

## Technischer Ansatz (High‑Level)

1. **View wiederverwenden als Partial**
   - Extrahiere die Termin‑Zusammenfassung aus Schritt‑4 in ein wiederverwendbares Partial, z. B. `application/views/appointments/partials/_appointment_summary.php`.
   - Binde das Partial in **Schritt 4** und in `book_success.php` ein.

2. **Datenversorgung**
   - `book_success.php` erhält bereits `$appointment_data` (inkl. `hash`). Falls einzelne Felder fehlen, lade per Server‑Side Lookup (by `id` oder `hash`) die vollständigen Termin‑/Provider‑/Service‑/Location‑Daten und reiche sie an die View.

3. **Kalender‑Links**
   - Implementiere einen Helper `application/helpers/calendar_helper.php` mit:
     - `build_google_calendar_link(array $event): string`
     - `build_outlook_link(array $event): string`
     - `build_ics_download_url(string $hash): string`
   - Zeitberechnung: `start` & `end` in UTC (für Google `YYYYMMDDTHHMMSSZ`, für Outlook ISO‑8601 mit `Z`) und zusätzlich TZ‑Angabe (`ctz`) falls erforderlich.

4. **ICS‑Endpunkt**
   - Neue Route `GET /appointments/ics/{hash}` → `Appointments::ics($hash)`.
   - Erzeuge die .ics mit z. B. **sabre/vobject** (oder per minimalem String‑Template), setze `UID`, `DTSTAMP`, `DTSTART`, `DTEND`, `SUMMARY`, `DESCRIPTION`, `LOCATION`, `TZID` (z. B. `Europe/Berlin`).  
   - Sende als Download (Einzeldatei).

5. **UI**
   - In `book_success.php`:
     - Oberhalb der Buttons: `include _appointment_summary.php`.
     - Darunter drei Buttons: **Google**, **Outlook**, **Apple/iOS (.ics)**.
   - Optional: kleines „Tipp“-Hinweisfeld oberhalb der Buttons beibehalten.

6. **Lokalisierung**
   - Neue Keys in `application/language/*/translations_lang.php`:
     - `appointment_overview_title`
     - `add_to_outlook_calendar`
     - `add_to_apple_calendar`
     - `open_calendar_options`
     - `download_ics` (falls benötigt)
   - Deutsche Texte entsprechend UI.

7. **Tests**
   - Unit‑Test für Helper (URL‑Encoding, Zeitformate, Zeitzone).
   - Smoke‑Test des ICS‑Downloads (HTTP‑Header, Dateiinhalt).
   - Manuelle QA auf iOS Safari, Android Chrome, Desktop (Chrome/Edge/Safari/Firefox).

---

## Akzeptanzkriterien (Definition of Done)

- [ ] Die Bestätigungsseite zeigt eine **vollständige Terminübersicht**, die in Inhalt und Format der Übersicht aus Schritt 4 entspricht.  
- [ ] Drei Buttons sind sichtbar: **Google**, **Outlook**, **Apple/iOS (.ics)**.  
- [ ] Google‑Link öffnet einen vorbefüllten Event‑Dialog mit korrektem Titel, Zeit (inkl. Sommerzeit), Ort und Beschreibung.  
- [ ] Outlook‑Link öffnet den Event‑Dialog in `outlook.office.com` (funktioniert auch für Outlook.com‑Konten).  
- [ ] iOS‑Link lädt eine `.ics`‑Datei; auf iPhone/iPad öffnet Safari den iOS‑Kalender‑Dialog.  
- [ ] Texte sind übersetzt (DE/EN) und UI ist responsiv.  
- [ ] Keine PII in Query‑Strings über das Nötige hinaus.  
- [ ] Lint/Static‑Checks laufen; CI grün.  

---

## Implementierungsdetails (konkret)

### 1) Routen
- `application/config/routes.php`  
  ```php
  $route['appointments/ics/(:any)'] = 'appointments/ics/$1';
  ```

### 2) Controller
- `application/controllers/Appointments.php`
  - Methode `public function book_success()` sicherstellen, dass `$appointment_data` vollständig ist (ggf. `Appointments_model` aufrufen).
  - **Neu:** `public function ics($hash)` – lädt Termin, baut .ics und liefert als Download:

    ```php
    public function ics($hash) {
        $appointment = $this->appointments_model->get_by_hash($hash);
        if (!$appointment) show_404();

        $tz = new DateTimeZone($this->config->item('timezone') ?: 'Europe/Berlin');
        $start = new DateTime($appointment['start_datetime'], $tz);
        $end   = new DateTime($appointment['end_datetime'], $tz);

        // Minimaler iCalendar-Output (alternativ sabre/vobject verwenden)
        $uid = $appointment['hash'].'@'.$this->config->item('base_url_host');
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Forscherhaus//EasyAppointments//DE\r\n"
             . "BEGIN:VEVENT\r\n"
             . "UID:$uid\r\n"
             . "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
             . "DTSTART;TZID=".$tz->getName().":" . $start->format('Ymd\THis') . "\r\n"
             . "DTEND;TZID=".$tz->getName().":" . $end->format('Ymd\THis') . "\r\n"
             . "SUMMARY:" . $this->escape_ics($appointment['title']) . "\r\n"
             . "DESCRIPTION:" . $this->escape_ics($appointment['description']) . "\r\n"
             . "LOCATION:" . $this->escape_ics($appointment['location']) . "\r\n"
             . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="Termin-'.$appointment['id'].'.ics"');
        header('Cache-Control: no-store');
        echo $ics;
    }
    ```

### 3) Helper
- `application/helpers/calendar_helper.php` (neu):

  ```php
  function build_google_calendar_link(array $e): string {
      // Google akzeptiert UTC-Zeiten im Format YYYYMMDDTHHMMSSZ
      $qs = http_build_query([
          'action'   => 'TEMPLATE',
          'text'     => $e['summary'],
          'dates'    => $e['start_utc'].'/'.$e['end_utc'],
          'details'  => $e['description'],
          'location' => $e['location'],
          'ctz'      => $e['tzid'], // z. B. Europe/Berlin
      ]);
      return 'https://calendar.google.com/calendar/render?'.$qs;
  }

  function build_outlook_link(array $e): string {
      // ISO‑8601 in UTC
      $qs = http_build_query([
          'path'    => '/calendar/action/compose',
          'rru'     => 'addevent',
          'subject' => $e['summary'],
          'body'    => $e['description'],
          'startdt' => $e['start_iso'], // 2025-11-26T12:00:00Z
          'enddt'   => $e['end_iso'],
          'location'=> $e['location'],
      ]);
      return 'https://outlook.office.com/calendar/0/deeplink/compose?'.$qs;
  }

  function build_ics_download_url(string $hash): string {
      return site_url('appointments/ics/'.$hash);
  }
  ```

  Hilfsfunktion zum Vorbereiten des Event‑Arrays (Konvertierung nach UTC und ISO‑8601) im Controller oder Model.

### 4) Views
- `application/views/appointments/partials/_appointment_summary.php` (neu) – Anzeige wie in Schritt 4 (Icon‑Liste, z. B. Ort/Datum/Zeitzone/Dauer).
- `application/views/appointments/book_success.php` – oberhalb der Buttons: `<?php $this->load->view('appointments/partials/_appointment_summary', $appointment_data); ?>`  
  Darunter die drei Buttons mit den aus dem Helper erzeugten URLs.

### 5) Styles
- Verwende vorhandene Button‑Klassen (Bootstrap) für konsistente Optik. Bei Bedarf kleine Utility‑Klasse für Button‑Gruppe auf Mobile (`display:block; margin-bottom:…`).

### 6) Lokalisierung
- Ergänze `application/language/de/translations_lang.php` und `application/language/en/translations_lang.php` um die neuen Schlüssel.
- Beispiel (DE):
  ```php
  $lang['appointment_overview_title'] = 'Terminübersicht';
  $lang['add_to_outlook_calendar']    = 'Zum Outlook‑Kalender hinzufügen';
  $lang['add_to_apple_calendar']      = 'Zum iOS/Apple‑Kalender (.ics)';
  ```

---

## Edge Cases

- Sommerzeit/Zeitzone (z. B. `Europe/Berlin`) – immer UTC generieren + `ctz` setzen.
- Mehrtägige/ganztägige Events – aktuell **nicht** vorgesehen (Non‑Ziel).
- Fehlende Raumangaben – „Ort“ leer lassen, Button bleibt funktionsfähig.
- Vergangene Termine – Buttons ausblenden.

---

## Rollback‑Plan
- Alle Änderungen sind additive (neue Partial‑View, Helper, Route).  
- Rückbau durch Entfernen der neuen Route, Entfernen der Buttons in `book_success.php` und Nicht‑Einbinden des Partials.

---

## Aufgaben (ToDos für Codex)

- [ ] Codebasis analysieren: Finde `book_success.php`, Schritt‑4‑Darstellung und Datenquellen (Model/Controller).
- [ ] Neues Partial `_appointment_summary.php` erstellen; bestehendes Markup aus Schritt 4 extrahieren; im Schritt 4 ersetzen und auf der Bestätigungsseite einbinden.
- [ ] `calendar_helper.php` implementieren (Google/Outlook/ICS‑URL Builder).
- [ ] Controller anpassen: Event‑Daten aggregieren (Titel, Beschreibung, Start/Ende, TZ, Ort, Hash) + UTC/ISO‑Konvertierung.
- [ ] Neue Route + `Appointments::ics($hash)` implementieren (Header, Inhalt, Tests).
- [ ] UI‑Buttons in `book_success.php` einfügen (Google/Outlook/iOS).
- [ ] Sprachschlüssel ergänzen (DE/EN) und Texte einsetzen.
- [ ] Manuelle QA (iOS Safari, Desktop Browser). Screenshots/Videos anhängen.
- [ ] PR eröffnen inkl. kurzer Doku (CHANGELOG‑Eintrag, Screenshots).

---

## Testplan (manuell)

1. **Funktionscheck**  
   - Termin buchen → Bestätigung. Terminübersicht zeigt Ort/Datum/Zeit/Dauer/Zeitzone korrekt.
2. **Google Button**  
   - Öffnet Event‑Dialog, Zeiten korrekt (bei DST & bei UTC‑Vergleich).
3. **Outlook Button**  
   - Öffnet compose‑View in `outlook.office.com`. Betreff/Ort/Zeiten gefüllt.
4. **iOS Button (.ics)**  
   - Auf iPhone/iPad: Safari → „Zum Kalender hinzufügen“.
5. **Lokalisierung**  
   - Deutsch & Englisch prüfen.
6. **Vergangenheit**  
   - Termin in der Vergangenheit buchen (oder Uhrzeit temporär zurücksetzen) → Buttons ausgeblendet.

---

## Übergabe an Review
- PR mit: Code‑Diff, kurze Doku, Screenshots (Desktop/Mobile), Test‑Checkliste, Hinweise zu Datenschutz & TZ‑Handling.