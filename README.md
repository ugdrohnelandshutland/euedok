# EÜDOK – Einsatz- & Übungsdokumentation

Browser-Werkzeug zum Erfassen, Ablegen und Versenden von Einsatz- und Übungsberichten.
Läuft auf normalem Webhosting (PHP ≥ 8.1, keine Datenbank). Jeder Bericht wird als JSON
und PDF gespeichert und beim Abschließen per Mail verschickt.

Konzept: [KONZEPT.md](KONZEPT.md) · UI-Entwurf: [design/](design/)

## Stand (Stufe 1)

- Anmeldung mit „angemeldet bleiben“, Sperre nach 5 Fehlversuchen, CSRF-Schutz
- Startseite, Berichtsformular (Handy/Tablet/PC, Tag/Nacht), Autosave auf Server und Gerät
- Abschließen → JSON + PDF (mPDF) + Mail (PHPMailer/SMTP), Korrekturen mit „Korrektur n“
- Übersicht mit Filter, PDF/JSON-Download, „Erneut senden“, ZIP-Export
- QR-Schnellerfassung (`/q/<key>`) mit optionaler PIN, Druckvorlage, eigenem PDF für 24 h
- Divera247: Alarme (Einsatz) bzw. Termine (Übung) übernehmen, Personal und Fahrzeuge live
- Einstellungsseite für alles, Änderungsprotokoll, Einrichtungsassistent

## Lokal entwickeln (XAMPP)

Voraussetzung: PHP-Erweiterungen `sodium`, `gd`, `zip`, `curl`, `mbstring` aktiv.

```bash
php composer.phar install
```

```bash
C:\xampp\php\php.exe -S localhost:8080 -t public tools/dev-router.php
```

Dann `http://localhost:8080/` öffnen – der Einrichtungsassistent startet beim ersten Aufruf.
Zum Testen ohne Mailserver unter Einstellungen → Mailversand „Nur Datei“ wählen; die Mails
landen dann in `data/mail-outbox/`.

## Auf dem Webhosting installieren (Netcup / Plesk)

1. Ordner `public/`, `app/`, `vendor/` hochladen (lokal vorher `composer install --no-dev`).
2. In Plesk den **Dokumentstamm** auf `…/euedok/public` stellen. `app/`, `vendor/` und `data/`
   liegen damit außerhalb des Web-Roots.
3. HTTPS (Let's Encrypt) aktivieren.
4. Adresse aufrufen → Einrichtungsassistent durchlaufen.
5. Unter Einstellungen Mailversand (SMTP-Postfach), Divera247-Schlüssel und QR-Zugänge eintragen.

**Update:** `public/`, `app/`, `vendor/` ersetzen. `data/` wird nie überschrieben.

## Ablage

```
data/
  settings/   Einstellungen (nur über die Einstellungsseite bearbeiten)
  keys/       Verschlüsselungsschlüssel – mit sichern!
  reports/    <Jahr>/Einsatzbericht_E-2026-001.json|.pdf
  logs/       Protokolle je Bereich (JSON-Zeilen), settings-audit.log
    divera-raw/  Divera-Rohantworten bei Fehlern/Übernahmen (30 Tage)
```

## Protokolle

Einstellungen → Protokolle zeigt die Einträge je Bereich (Anwendung, Anmeldung, Berichte,
Mailversand, PDF, Divera247, QR, Fehler) mit Filter und Details; „Alle als ZIP“ lädt alles herunter.
Jede Zeile trägt eine Anfrage-ID, über die zusammengehörige Einträge gefunden werden.
Rotation bei 20 MB je Datei, 5 alte Dateien bleiben. Passwörter, PINs, QR- und API-Schlüssel
werden vor dem Schreiben unkenntlich gemacht. Stufe „Debug“ protokolliert zusätzlich jede Anfrage.

Sicherung: `data/` komplett sichern (inkl. `keys/`, sonst sind SMTP-Passwort und Divera-Schlüssel
nicht mehr lesbar). Nach dem Zurückspielen: Einstellungen → Protokoll → „Übersicht neu aufbauen“.
