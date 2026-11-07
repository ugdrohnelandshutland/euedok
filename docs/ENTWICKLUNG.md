# EÜDOK – Entwicklung, Release, Betrieb

Konzept und Datenmodell: [KONZEPT.md](../KONZEPT.md)

## Lokal entwickeln (XAMPP)

Voraussetzung: PHP ≥ 8.1 mit den Erweiterungen `sodium`, `gd`, `zip`, `curl`, `mbstring`.

```bash
php composer.phar install
```

```bash
C:\xampp\php\php.exe -S localhost:8080 -t public tools/dev-router.php
```

Dann `http://localhost:8080/` öffnen – der Einrichtungsassistent startet beim ersten Aufruf.
Zum Testen ohne Mailserver unter Einstellungen → Mailversand „Nur Datei“ wählen; die Mails
landen dann in `data/mail-outbox/`.

Der eingebaute PHP-Server liest keine `.htaccess`. Neue Adressen deshalb immer in
`public/.htaccess` **und** `tools/dev-router.php` eintragen.

## Release veröffentlichen

1. `EUEDOK_VERSION` in `app/bootstrap.php` setzen und committen.
2. `tools\build-release.ps1 -Version x.y.z` baut und signiert `build\euedok-x.y.z.zip` + `.zip.sig`.
   Der Signaturschlüssel wird einmalig mit `php tools/release-tool.php keygen` erzeugt und liegt
   nur unter `%USERPROFILE%\.euedok\`.
3. `main` und Tag pushen, auf GitHub ein Release zum Tag anlegen und beide Dateien anhängen.

Installationen finden das Release dann unter Einstellungen → Update.

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

## Sicherung

`data/` komplett sichern (inkl. `keys/`, sonst sind SMTP-Passwort und Divera-Schlüssel nicht mehr
lesbar). Nach dem Zurückspielen: Einstellungen → Protokoll → „Übersicht neu aufbauen“.

## Vorschaubild für GitHub

`node design/github/render.mjs` rendert `design/github/social-preview.html` als
`social-preview.png` (1280×640). Hochladen unter GitHub → Settings → Social preview.
