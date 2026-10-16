# EÜDOK – Konzept

Name: **EÜDOK** – Einsatz- & Übungsdokumentation (technische Schreibweise für Ordner/Dateien:
`euedok`)
Stand: 15.10.2026 · Erste Nutzer: UG-Drohne Landshut Land (ca. 5 Personen mit Zugang)
Status: **Stufe 1 umgesetzt (15.10.2026) – Divera mit echtem Schlüssel noch zu prüfen**

## 1. Ziel

Webbasiertes Tool zum Erstellen und Ablegen von Einsatz- und Übungsberichten, das
im Browser auf **Handy, Tablet und PC** bedient wird und auf einem gewöhnlichen
Webhosting-Paket (z. B. Netcup) läuft. Jeder Bericht wird als **JSON** (für spätere
Auswertungen, Importe, andere Exporte) und als **PDF** (zum Ablegen/Weitergeben)
gespeichert.

Oberstes Ziel der Bedienung: **extrem schnell und einfach auszufüllen** – auch mit
dem Handy direkt nach dem Einsatz am Fahrzeug.

Das Tool ist **allgemeingültig** aufgebaut: Alles Einheits-Spezifische steht in der
Konfiguration, nicht im Code. Jede Einheit / Feuerwehr betreibt **eine eigene
Installation**. Eine Veröffentlichung ist vorerst nicht geplant.

## 2. Festgelegte Entscheidungen

| Thema | Entscheidung |
|---|---|
| Technik | PHP ≥ 8.1, kein Framework, keine Datenbank |
| Speicherung | JSON-Dateien + PDF im Dateisystem, außerhalb des Web-Roots |
| Zugänge | Verwaltung im Browser (Einstellungen → Zugänge); Passwörter nur als bcrypt-Hash gespeichert |
| Technische Namen | **Business-Englisch** für alle Pfade, Ordner, Dateinamen, URLs, JSON-Schlüssel und IDs (z. B. `reports/`, `report.php`, `flight_minutes`). **Deutsch** nur für Dokumentation und Oberfläche (Texte, Beschriftungen, Feldbezeichnungen). Siehe Abschnitt 8a |
| Einstellungen | **Ausschließlich über die Einstellungsseite im Browser – schon ab Stufe 1.** Keine Konfigurationsdateien, die von Hand bearbeitet werden; Ersteinrichtung per Assistent |
| Rollen | Keine Freigabe-Rollen; jeder schließt seine Berichte selbst ab. Einziges Merkmal: „darf Einstellungen ändern“. Zusätzlich technische QR-Zugänge, die nur „Bericht erstellen“ dürfen (7b) |
| Unterschriften | Keine. PDF trägt Vermerk „Abgeschlossen durch … am …“ |
| PDF-Layout | Fest, modern und sauber; Farben, Kopf-/Fußzeile aus Konfiguration |
| PDF-Erzeugung | Serverseitig (sieht auf jedem Gerät gleich aus) |
| Logo | Erst zum Release; bis dahin Platzhalter (Name der Einheit als Schriftzug) |
| Mandanten | Eine Installation pro Einheit |
| Personalliste im PDF | Nur eingesetzte Personen (nicht alle mit Leerzeilen) |
| Funktionen | **Gestrichen** – weder im Bericht noch in der Verwaltung |
| Personenfelder | Je eingesetzter Person **optionale Zusatzfelder**, in der Verwaltung konfigurierbar (wie bei den Fahrzeugen). UG-Drohne: „Flugminuten“ |
| Flugzeit | Nur pro Person (nicht pro Drohne) – umgesetzt als konfiguriertes Personenfeld „Flugminuten“ (optional, mit Summe) |
| Auswertungen | **Nicht Teil dieses Projekts** (auch keine Jahresauswertung) – erfolgen extern auf Basis der JSON-Dateien |
| Pflichtfelder | Konfigurierbar; Standard: Art, Beginn, Ende, Ort, Thema, mind. eine Person, Leiter |
| Alarmierung | Konfigurierbare Schnellauswahl **plus** freie Eingabe abweichender Quellen; frei eingegebene Quellen bleiben nur im jeweiligen Bericht (kein neuer Vorschlag) |
| Konfigurierbarkeit | **Alle** Listen und Einstellungen sind konfigurierbar (Personal, Geräte, Fahrzeuge, Alarmierung, Berichtsarten, Kopf/Fußzeile …) – nichts ist im Code festgeschrieben |
| Funkloch-Schutz | Entwurf wird zusätzlich im Browser zwischengespeichert |
| Mailversand | Abgeschlossene Berichte werden automatisch mit PDF + JSON im Anhang an die in den Einstellungen hinterlegte(n) Adresse(n) geschickt (siehe Abschnitt 7a) |
| QR-Schnellerfassung | QR-Aufkleber im Fahrzeug / Gerätehaus führt **ohne Anmeldung** über einen kryptischen Link direkt zu „Neuer Bericht“ – nur erstellen und verschicken, nichts ansehen (siehe Abschnitt 7b) |
| Divera247 – Einsatzdaten | Optional, sauber getrennt: **Einsatz** ← Divera-**Alarme**, **Übung** ← Divera-**Termine** der letzten 24 h; gefundene Daten inkl. Personal (Rückmeldung bzw. Zusage) werden nach Rückfrage übernommen – auch über den QR-Zugang (siehe Abschnitt 7c) |
| Divera247 – Personal | Optional einschaltbar: Personalliste wird **live** aus Divera247 abgerufen statt in EÜDOK gepflegt – kein Sync, keine doppelte Pflege (siehe Abschnitt 7c) |
| Fahrzeuge | Optionales Modul: eingesetzte Fahrzeuge erfassen, je Fahrzeug **optionale Zusatzfelder** (konfigurierbar; UG-Drohne: nur „Kilometer“ = gefahrene km); Fahrzeugquelle wie beim Personal: **EÜDOK-Liste oder Divera247 live**; erscheint auch im PDF (siehe Abschnitt 7d) |
| Gestaltung | Passend zum bestehenden **Drone Live Stream Hub** der UG-Drohne: Navy/Teal, Schemata „Tag“ und „Nacht“, Systemschriften, gleiche Radien, Linien und Wortmarke (siehe Abschnitt 3a) |

## 3. Bedienkonzept – schnell und einfach

**Grundsätze**
- **Mobile first:** Eine Spalte auf dem Handy, zwei Spalten auf Tablet/PC
- **Große Tippflächen** (mind. 48 px – wie im Drone Live Stream Hub), keine winzigen Checkboxen
- **Tippen statt Tippen:** Auswahl-Kacheln („Chips“) statt Dropdowns, wo immer möglich
- **Passende Tastaturen:** Zahlenfeld für Zahlenfelder (z. B. Flugminuten, Kilometer), Datums-/Uhrzeit-Auswahl des Geräts
- **Kein Speichern-Vergessen:** automatisches Zwischenspeichern bei jeder Änderung
- **Sehr schnell:** kein Framework, keine externen Bibliotheken oder Schriften im
  Browser, Seiten wenige KB groß – lädt auch bei schlechtem Netz sofort
- **Wie eine App:** lässt sich über „Zum Startbildschirm hinzufügen“ mit eigenem Symbol
  starten (Web-App-Manifest)

**Startseite**
- Zwei große Knöpfe: **„Neuer Einsatz“** und **„Neue Übung“**
- Darunter: eigene offene Entwürfe, dann die letzten Berichte

**Sinnvolle Vorbelegungen**
- Beginn = jetzt (auf 5 min gerundet), Ende mit Knopf **„Jetzt“** setzen
- Dauer wird automatisch berechnet (auch über Mitternacht)
- Angemeldeter Benutzer ist bereits als Person eingetragen
- Falls Divera247 aktiv: Kopfdaten und Personal nach Rückfrage übernehmen – bei Einsätzen
  aus dem Alarm, bei Übungen aus dem Termin (Abschnitt 7c)
- Thema/Stichwort: Vorschläge aus früheren Berichten beim Tippen

**Personal auswählen**
- Alle aktiven Personen als Kacheln, **häufig eingesetzte zuerst**, mit Suchfeld
- Antippen = hinzugefügt; darunter erscheinen pro Person die konfigurierten
  **Personenfelder** – bei der UG-Drohne nur **„Flugminuten“** (optional, Zahlentastatur)
- Bei Zahlenfeldern mit „Summe“ wird die Summe live angezeigt (z. B. Flugzeit gesamt)
- Keine Funktionen (bewusst gestrichen)

**Geräte auswählen**
- Alle aktiven Geräte als Kacheln zum An-/Abtippen

**Fahrzeuge** (nur wenn das Modul aktiv ist)
- Aktive Fahrzeuge als Kacheln; je gewähltem Fahrzeug die konfigurierten
  **Fahrzeugfelder** – bei der UG-Drohne nur **„Kilometer“** (optional) – Details Abschnitt 7d

**Alarmierung**
- Konfigurierte Quellen als Kacheln zur Schnellauswahl
- Kachel **„Andere …“** öffnet ein Textfeld für abweichende Quellen
- Frei eingegebene Quellen werden nur im Bericht gespeichert und **nicht** als neuer
  Vorschlag übernommen – die Schnellauswahl ändert sich nur über die Konfiguration

**Abschließen**
- Ein Knopf; fehlende Pflichtfelder werden klar markiert und angesprungen
- Danach sofort PDF zum Ansehen / Teilen
- Rückmeldung „Per Mail versendet an …“ bzw. deutlicher Hinweis, falls der Versand
  fehlgeschlagen ist

## 3a. Gestaltung – passend zum Drone Live Stream Hub

EÜDOK soll aussehen wie ein Teil der Werkzeuge, die die UG-Drohne schon nutzt
(Drone Live Stream Hub, `ugdrohnelandshutland/drone-live-stream-hub`, ADR 0010
„Navy and teal look“). Übernommen werden nur die Gestaltungswerte, kein Code:

- **Zwei Schemata wie im Hub:** „Nacht“ (Navy: Grund `#09111c`, Flächen `#102031`,
  Linien `#294057`/`#476079`, Text `#e3edf7`/`#a4bacb`, Akzent Teal `#58d5cc`,
  gefüllte Bedienelemente `#258078`) und „Tag“ (Grund `#e8eef3`, Flächen weiß,
  Linien `#b9c8d4`, Text `#0b1a28`/`#3b5063`, Teal `#0f6f68`/`#1d6f68`).
  Automatisch nach Gerät, umschaltbar mit dem gleichen Tag/Nacht-Schalter.
- **Teal heißt „bedienbar“:** gewählte Kacheln, eingeschaltete Schalter, Hauptknöpfe,
  Fokus. Signalfarben bleiben Zuständen vorbehalten: Grün `#0b7a3e` = abgeschlossen /
  versendet, Bernstein `#f2b01e` = Hinweis / Mail-Fehler, Rot `#c4202b` = Fehler,
  Pflichtangabe fehlt – und als einziges „lautes“ Element der Block der Berichtsart
  **Einsatz** (wie die Slot-Nummer im Hub); Übung in ruhigem Navy/Grau.
- **Formen:** Radien 7 px (Knöpfe, Felder), 12 px (Karten, Kacheln), 16 px (Dialoge);
  1-px-Linien; weicher Schatten nur bei Dialogen und Meldungen.
- **Schrift:** Systemschriften (`system-ui`, Segoe UI, Roboto …); Wortmarke im Kopf
  wie „Drohnen-**Livestream**“: „EÜ**DOK**“ mit Teal-Teil; daneben ein Label in
  gesperrten Großbuchstaben (z. B. „UG-DROHNE LANDSHUT LAND“), dünne Linie unter
  dem Kopf.
- **Karten wie die Drohnen-Karten:** farbiger Block links (Einsatz/Übung statt
  Slot-Nummer), daneben Titel und Metazeile.
- **Tippflächen ≥ 48 px**, Hover nur mit Maus, `:active` als Rückmeldung bei Touch.
- **App-Symbol** aus derselben Familie: Navy-Quadrat, Teal-Schild, darin ein Bericht
  mit Haken.

## 4. Konfigurierbar pro Einheit

**Alles, was hier steht, wird ausschließlich über die Einstellungsseite im Browser
gepflegt – von Anfang an (Stufe 1).** Niemand muss Dateien auf dem Server bearbeiten.
Zugriff haben nur Zugänge mit dem Recht „Einstellungen ändern“. Intern legt EÜDOK die
Einstellungen als Dateien im geschützten Bereich `data/settings/` ab – das ist reine
Speicherung, kein Bearbeitungsweg. Beim ersten Aufruf führt ein **Einrichtungsassistent**
durch Name der Einheit, ersten Zugang (mit Einstellungsrecht) und Grundwerte.

Bereiche der Einstellungsseite: Einheit (Name, Farben, Kopf/Fußzeile, Logo) ·
Berichtsarten & Nummern · Pflichtfelder & Module · Zusatzfelder · Personal · Geräte ·
Fahrzeuge · Alarmierung · Mailversand · Divera247 · Zugänge · QR-Zugänge

- Name, Untertitel, Logo (ab Release), Akzentfarbe, Fußzeile (Leitung, Adresse, Kontakt)
- Berichtsarten (Standard: Einsatz, Übung; erweiterbar z. B. Ausbildung, Wartung)
- Nummernformat je Art (Standard: `E-{YYYY}-{NNN}`, `U-{YYYY}-{NNN}`, Zählung pro Jahr)
- Module an/aus: Personal, Geräte, **Fahrzeuge**, Defekte/Vorfälle, Alarmierung
- Pflichtfelder zum Abschließen (Standard: Art, Beginn, Ende, Ort, Thema,
  mind. eine Person, Leiter)
- **Zusatzfelder** – drei Arten, gleiche Feldtypen (Zahl, Text, Auswahl, Haken, Datum),
  jeweils mit Bezeichnung, Einheit (z. B. „min“, „km“), optional/Pflicht, Reihenfolge und –
  bei Zahlen – „Summe anzeigen“; erscheinen in Formular, PDF und JSON:
  - **Berichtsfelder** – einmal je Bericht
  - **Personenfelder** – je eingesetzter Person (UG-Drohne: „Flugminuten“, Zahl, min, Summe)
  - **Fahrzeugfelder** – je eingesetztem Fahrzeug (UG-Drohne: „Kilometer“, Zahl, km, Summe)
  Felder werden deaktiviert statt gelöscht, damit alte Berichte vollständig bleiben.
- **Mailversand:** an/aus, Empfänger (eine oder mehrere Adressen), Absender,
  SMTP-Zugang, Betreffvorlage (siehe Abschnitt 7a)
- **Divera247:** an/aus, API-Schlüssel, Zeitfenster, Personalquelle (EÜDOK oder Divera),
  Fahrzeugquelle, Zuordnung der Rückmeldungen (siehe Abschnitt 7c)
- **Stammdaten:**
  - **Personal** – Name, aktiv/inaktiv. Entfällt, wenn die
    Personalquelle „Divera247“ eingestellt ist.
  - **Geräte** – Bezeichnung, aktiv/inaktiv
  - **Fahrzeuge** (optional) – EÜDOK-Liste oder live aus Divera247 (Bezeichnung,
    Funkrufname, Kennzeichen, aktiv/inaktiv) (Abschnitt 7d)
  - **Alarmierungsquellen** – Schnellauswahl-Liste

Ausgeschiedene Personen / Geräte / Fahrzeuge werden auf *inaktiv* gesetzt, nicht gelöscht – so
bleiben alte Berichte und Auswertungen vollständig.

### Startkonfiguration UG-Drohne (festgelegt)

Alle Listen sind nur Startwerte, die bei der Einrichtung vorbelegt werden, und jederzeit
über die Einstellungsseite im Browser änderbar.

**Alarmierungsquellen:** ILS Landshut · Polizei · Einsatzleitung Feuerwehr vor Ort ·
Kreisbrandinspektion · Landratsamt Landshut · Übungsplan / eigene Planung

**Geräte:** Drohne M30T - 1 · Drohne M30T - 2 · Monitor · Tablet · Notebook ·
Powerstation · Suchscheinwerfer · Akku-Arbeitsscheinwerfer

**Personal:** vorerst **5 Dummy-Datensätze** zum Testen (z. B. „Muster, Max“). Echte
Namen werden erst später eingetragen – oder direkt aus Divera247 abgerufen.

**Personenfelder:** „Flugminuten“ (Zahl, min, optional, Summe anzeigen).

**Fahrzeugfelder:** „Kilometer“ (Zahl, km, optional).

**Fahrzeuge:** Modul vorerst aus; zum Testen ein Dummy-Fahrzeug „MTW UG-Drohne“.

**Divera247:** vorbereitet, aber aus, bis ein API-Schlüssel hinterlegt ist.

## 5. Berichtsinhalt (Basis: bisheriges Formular)

**Kopf:** Art · Nummer (automatisch) · Beginn · Ende · Dauer (berechnet) · Ort ·
Thema/Stichwort · Alarmierung durch

**Bericht / getroffene Maßnahmen** – Freitext, beliebige Länge

**Defekte / Vorfälle** – Freitext; Hinweis „Defekte sind sofort dem Leiter zu melden“

**Eingesetzte Geräte** – Auswahl aus Stammdaten

**Eingesetzte Fahrzeuge** (optional) – Auswahl aus Stammdaten oder live aus Divera247, je
Fahrzeug die Fahrzeugfelder (UG-Drohne: „Kilometer“, optional)

**Eingesetztes Personal** – Auswahl aus Stammdaten oder live aus Divera247, je Person
die Personenfelder (UG-Drohne: „Flugminuten“, optional)

**Einsatz-/Übungsleiter** – Auswahl aus dem eingesetzten Personal

**Divera-Bezug** (wenn übernommen) – Alarm-Nummer und Alarmtext aus Divera247

## 6. Auswertungen

**Nicht Teil dieses Projekts.** Das Tool erfasst und legt ab; Auswertungen (z. B.
Flugzeit pro Person und Jahr) erfolgen extern. Dafür sind die JSON-Dateien so
aufgebaut, dass sie ohne das Tool vollständig lesbar sind (IDs + Bezeichnungen,
Flugminuten pro Person, Berichtsart, Zeitraum). Alle Berichte lassen sich als ZIP
herunterladen.

## 7. Abläufe

1. **Neuer Bericht:** „Neuer Einsatz“ / „Neue Übung“ → Nummer wird vergeben
2. **Ausfüllen:** wird laufend automatisch gespeichert
3. **Entwurf:** jederzeit weiter bearbeitbar, auch von einem anderen Gerät
4. **Abschließen:** JSON + PDF werden geschrieben und **per Mail versendet**
5. **Nachträgliche Änderung:** erlaubt; PDF wird neu erzeugt, Änderung wird im
   JSON protokolliert (wer, wann) und die neue Fassung **erneut per Mail versendet**
6. **Übersicht:** Liste nach Jahr/Art, Suche, PDF-Download, ZIP-Export aller JSON,
   Versandstatus je Bericht

## 7a. Automatischer Mailversand

**Auslöser:** ausschließlich das **Abschließen** (bestätigt). Eine Korrektur an einem
bereits abgeschlossenen Bericht wird ebenfalls über „Abschließen“ gespeichert und
deshalb erneut versendet. Entwürfe und das automatische Zwischenspeichern lösen
**keinen** Versand aus.

**Inhalt der Mail**
- Betreff (Vorlage konfigurierbar), z. B.
  `[EÜDOK] Einsatz E-2026-003 – Vermisste Person – 12.10.2026`
- Bei Änderungen: Betreff mit Zusatz `– Korrektur 1`, `– Korrektur 2` …
- Text: kurze Zusammenfassung (Art, Nummer, Datum, Ort, Thema, Leiter, abgeschlossen
  durch)
- Anhänge: `Einsatzbericht_E-2026-003.pdf` und `Einsatzbericht_E-2026-003.json`

**Technik**
- Versand per **SMTP** über ein normales Postfach (z. B. bei Netcup angelegtes
  `berichte@example.org`) – zuverlässiger als die PHP-Funktion `mail()`,
  die oft im Spam landet
- Mail-Bibliothek PHPMailer (einmalig mit hochgeladen, keine Installation nötig)
- Mehrere Empfänger möglich (z. B. Leitung + Ablage-Postfach)

**Fehlerfall**
- Der Bericht ist **immer** gespeichert, auch wenn der Versand scheitert
- Fehlgeschlagener Versand wird in der Übersicht markiert, mit Knopf
  **„Erneut senden“**
- Jeder Versandversuch steht in der `history` des Berichts (wann, an wen, Erfolg/Fehler)

**Einstellungen** (Einstellungsseite → Mailversand):
an/aus · Empfänger · Absendername/-adresse · SMTP-Server, Port, Benutzer, Passwort ·
Betreffvorlage · Testmail-Knopf

## 7b. Schnellerfassung per QR-Code (ohne Anmeldung)

**Warum:** Einsätze kommen plötzlich. Im Stress ist man nicht angemeldet, hat das
Passwort nicht im Kopf oder das eigene Handy nicht dabei. Der Bericht soll trotzdem
sofort erfasst werden können.

**Ablauf**
1. Im Fahrzeug / Gerätehaus klebt ein QR-Code („EÜDOK – Einsatzbericht erstellen“)
2. Handy-Kamera drauf → der kryptische Link öffnet **direkt das Formular „Neuer Bericht“**
   – ohne Anmeldung, ohne Startseite
3. Ausfüllen wie gewohnt; zusätzliches Pflichtfeld **„Erstellt durch“** (Auswahl aus dem
   Personal, da niemand angemeldet ist). Das Gerät merkt sich die letzte Auswahl.
4. Abschließen → JSON + PDF + Mail wie gewohnt
5. Bestätigung mit Berichtsnummer und **„PDF ansehen / herunterladen“** – damit der
   Gruppenführer prüfen kann, was er ausgefüllt hat; danach „Weiteren Bericht erstellen“

**Was über den QR-Zugang möglich ist – und was nicht**

| Möglich | Nicht möglich |
|---|---|
| Neuen Bericht erstellen (Einsatz vorausgewählt, Übung umschaltbar) | Übersicht, alte oder fremde Berichte ansehen |
| Eigenen, noch offenen Entwurf **auf demselben Gerät** fortsetzen | Andere abgeschlossene Berichte öffnen oder herunterladen; Berichte korrigieren |
| Abschließen und versenden | Einstellungen, Zugänge, ZIP-Export |
| **PDF des gerade abgeschlossenen Berichts** ansehen / herunterladen / teilen | |
| | Thema-Vorschläge aus früheren Berichten (würden alte Inhalte verraten) |

Sichtbar sind nur die Auswahllisten, die zum Ausfüllen nötig sind: Personal (Namen),
Geräte, Fahrzeuge, Alarmierungsquellen – und, falls Divera aktiv ist, das
Popup mit Alarmen der letzten 24 h (7c).

**Technik**
- Link mit kryptischem Schlüssel, z. B. `https://<adresse>/q/7KQ2-M9XF-…`
  (mind. 128 Bit Zufall – nicht erratbar). Gespeichert wird auf dem Server nur ein Hash.
- Im Hintergrund ein **technischer Dienst-Zugang** je QR-Code (z. B. „qr-mtw“) mit genau
  einem Recht: „Bericht erstellen“. Kein Passwort-Login für diesen Zugang möglich.
- Beim Öffnen des Links entsteht eine kurze, auf das Formular beschränkte Sitzung.
- **PDF nach dem Abschließen:** Der Download-Link gilt nur für den eben abgeschlossenen
  Bericht, nur in dieser Sitzung auf diesem Gerät und nur befristet (z. B. 24 Stunden);
  danach ist das PDF über den QR-Zugang nicht mehr abrufbar. Das JSON wird über QR nicht
  angeboten. Korrekturen weiterhin nur nach Anmeldung.
- **Optionale PIN (je QR-Zugang einstellbar, Standard: aus):**
  - 4–6-stellige Zahl, Abfrage mit großer Zifferntastatur direkt nach dem Scannen
  - Auf dem Gerät für eine einstellbare Zeit gemerkt (z. B. 30 Tage), damit im Einsatz
    nicht jedes Mal getippt werden muss; „jedes Mal fragen“ ebenfalls möglich
  - Schutz gegen Raten: nach 10 falschen PINs innerhalb einer Stunde ist der QR-Zugang
    1 Stunde komplett gesperrt (unabhängig von Gerät/IP, auch für gemerkte Geräte),
    Hinweis per Mail an die Leitung; Schlüssel erneuern hebt die Sperre sofort auf
  - PIN wird nur als Hash gespeichert; Ändern der PIN macht gemerkte Geräte ungültig
  - Sinnvoll z. B., wenn der Aufkleber an einer Stelle hängt, an die auch Fremde kommen
- **Mehrere QR-Zugänge** möglich, jeweils mit Bezeichnung (z. B. „MTW UG-Drohne“,
  „Gerätehaus“); jeder einzeln **sperrbar und erneuerbar** (neuer Schlüssel → neuer
  Aufkleber, alter Link sofort ungültig).
- Entwürfe: werden wie üblich laufend gespeichert (Gerät + Server). Auf dem QR-Gerät
  über ein Geräte-Cookie fortsetzbar; angemeldete Nutzer sehen den Entwurf in der
  Übersicht (Kennzeichen „QR“) und können ihn fertigstellen.
- Kennzeichnung: In der JSON-`history` `"source": "qr:mtw"`, im PDF-Vermerk
  „Abgeschlossen durch Max Muster (Schnellerfassung · MTW UG-Drohne) am …“,
  in der Übersicht ein „QR“-Kennzeichen.

**Schutz gegen Missbrauch**
- Begrenzung: z. B. höchstens 5 abgeschlossene Berichte pro Stunde und QR-Zugang
- Keine Indizierung durch Suchmaschinen (`noindex`), Link taucht nirgends öffentlich auf
- Jeder über QR erstellte Bericht geht sofort per Mail an die Leitung → Missbrauch fällt
  sofort auf; Sperre mit einem Klick
- Aufkleber nur **innen** im Fahrzeug / Gerätehaus anbringen

**QR-Aufkleber**
- Einstellungsseite „QR-Zugänge“ (ab Stufe 1): Zugang anlegen, Bezeichnung, PIN an/aus,
  sperren, Schlüssel erneuern – und **Druckvorlage** für den Aufkleber (QR-Code,
  Bezeichnung, Hinweis „Nur für Mitglieder der Einheit“) als PDF

## 7c. Anbindung Divera247 (optional)

Die UG-Drohne alarmiert über **Divera247**. Divera bietet eine Web-API, die mit einem
API-Schlüssel (Accesskey) der Einheit abgefragt wird. EÜDOK nutzt sie **nur lesend** und
nur, wenn sie in den Einstellungen eingeschaltet ist. Andere Einheiten ohne Divera
merken davon nichts.

### A) Einsatzdaten beim Anlegen eines Berichts übernehmen

**Ablauf**
1. „Neuer Einsatz“ oder „Neue Übung“ (auch über QR) → EÜDOK fragt im Hintergrund bei
   Divera247 nach – **streng getrennt:**
   - **Neuer Einsatz → nur Alarme** der letzten 24 Stunden
   - **Neue Übung → nur Termine** der letzten 24 Stunden (keine Alarme)
   Zeitfenster einstellbar.
2. Nichts gefunden → das Formular öffnet sich ganz normal, kein Hinweis nötig.
3. Gefunden → **Popup** „Einsatz in Divera gefunden“ bzw. „Übung in Divera gefunden“ mit
   Stichwort/Titel, Zeit, Adresse und Anzahl Rückmeldungen/Zusagen. Knöpfe: **„Übernehmen“** / **„Ohne Divera-Daten weiter“**.
4. Mehrere Alarme bzw. Termine im Zeitfenster → Liste zur Auswahl (neuester oben).
5. Ein Alarm/Termin, der schon in einem EÜDOK-Bericht übernommen wurde, ist gekennzeichnet
   („bereits in E-2026-003“), damit kein doppelter Bericht entsteht.

**Einsatz – was aus dem Alarm übernommen wird** (soweit vorhanden; alles bleibt änderbar)

| EÜDOK-Feld | aus Divera247 |
|---|---|
| Beginn | Alarmzeit |
| Ende | Abschlusszeit, falls der Alarm in Divera bereits beendet ist |
| Thema / Stichwort | Alarmstichwort / Titel |
| Ort | Einsatzadresse |
| Alarmierung durch | einstellbare Standard-Quelle für Divera-Alarme (z. B. „ILS Landshut“) |
| Personal | Personen, deren Rückmeldung einem der als „eingesetzt“ markierten Status entspricht (Mehrfachauswahl, UG-Drohne: „JA zur Drohne“, „JA zur ES“) |
| Fahrzeuge | dem Alarm zugeordnete Divera-Fahrzeuge (wenn das Fahrzeug-Modul aktiv ist) |
| Divera-Bezug | Alarm-Nummer und Alarmtext (im JSON; im PDF optional) |

Der Freitext „Bericht / Maßnahmen“ wird **nicht** vorbefüllt – er beschreibt, was
tatsächlich passiert ist.

**Übung – was aus dem Termin übernommen wird** (bestätigt): Quelle sind **ausschließlich**
Divera-**Termine** (z. B. Übungsabend) der letzten 24 Stunden – Alarme werden bei
Übungen nicht angeboten.

| EÜDOK-Feld | aus dem Divera-Termin |
|---|---|
| Beginn / Ende | Beginn und Ende des Termins |
| Thema | Titel des Termins |
| Ort | Ort des Termins |
| Alarmierung durch | einstellbare Standard-Quelle für Übungen (z. B. „Übungsplan / eigene Planung“) |
| Personal | Personen mit **Zusage** – als Vorauswahl, jederzeit änderbar |
| Fahrzeuge | keine (Termine haben keine Fahrzeugzuordnung) – manuell wählen |
| Divera-Bezug | Termin-Nummer und Beschreibung (im JSON) |

Die genauen API-Felder für Termine und Zusagen werden bei der Umsetzung geprüft.

### B) Personal live aus Divera247 (statt eigener Liste)

- Einstellung **Personalquelle:** „EÜDOK-Liste“ (Standard) oder „Divera247 (live)“
- Bei „Divera247“ wird die Personalliste bei Bedarf **live** abgerufen (Formular öffnen,
  Personal-Auswahl, QR „Erstellt durch“) – **kein Abgleich, keine Kopie zum Pflegen**.
  Die Personalseite in den Einstellungen zeigt dann nur „wird in Divera247 verwaltet“.
- **Ausfallsicherheit:** Ist Divera nicht erreichbar, wird die zuletzt erfolgreich
  abgerufene Liste verwendet und als „Stand: …“ gekennzeichnet – reiner Puffer, kein Sync
  (bestätigt). Ein Gruppenfilter ist für die UG-Drohne nicht nötig.
- **Zuordnung Zugang ↔ Person:** Jeder EÜDOK-Zugang wird einmalig einer Person
  (Divera-Benutzer) zugeordnet, damit „angemeldete Person ist eingetragen“ weiter klappt.
- Berichte speichern Name **und** Divera-Benutzer-ID → alte Berichte bleiben vollständig,
  auch wenn jemand in Divera gelöscht wird.

### Technik & Datenschutz
- Abruf ausschließlich **serverseitig** (PHP → Divera-API über HTTPS). Der API-Schlüssel
  wird auf der Einstellungsseite eingegeben, serverseitig verschlüsselt gespeichert und
  danach nie wieder angezeigt (nur „hinterlegt ✓“ / „ersetzen“); er kommt **nie** in den
  Browser.
- Divera-API v2 (u. a. Alarme mit Rückmeldungen, Benutzer/Gruppen der Einheit). Die
  genauen Endpunkte und Felder werden bei der Umsetzung gegen die Divera-API-Doku und
  einen Test-Schlüssel geprüft.
- Kurzes Zeitlimit (ca. 3 s): Ist Divera langsam oder nicht erreichbar, öffnet das
  Formular trotzdem sofort – Hinweis „Divera nicht erreichbar – bitte manuell ausfüllen“.
- Es werden nur die übernommenen Daten gespeichert; Divera bleibt die Quelle.
- **QR-Zugang (bestätigt):** Über den QR-Code wird Divera ebenfalls abgefragt – gerade im
  Stress hilfreich. Das Popup zeigt dann nur Alarme/Termine des Zeitfensters, keine
  Berichte. Für andere Einheiten abschaltbar.

**Einstellungen** (Einstellungsseite → Divera247):
an/aus · API-Schlüssel · Zeitfenster (Standard 24 h) · für Einsätze / für Übungen ·
Standard-Alarmierungsquelle je Berichtsart · Rückmeldestatus, die als „eingesetzt“ gelten ·
Personalquelle · Fahrzeugquelle · Divera-Abfrage über QR an/aus · Testabruf-Knopf

**Rückmeldestatus „war beim Einsatz dabei“ (Mehrfachauswahl):**
- Die Liste der Rückmeldestatus wird live aus Divera247 geladen und als Häkchen-Liste
  angezeigt; **beliebig viele** Status können als „eingesetzt“ markiert werden.
- Wer im Alarm einen dieser Status gemeldet hat, ist im Bericht vorausgewählt; alle
  anderen Rückmeldungen (z. B. „Nein“, „Später“) werden ignoriert.
- **UG-Drohne:** „JA zur Drohne“ und „JA zur ES“.
- Gespeichert werden Divera-Status-ID **und** Bezeichnung; wird ein Status in Divera
  umbenannt oder gelöscht, erscheint in den Einstellungen ein Hinweis.
- Bei Übungen gelten stattdessen die **Zusagen** zum Divera-Termin (eigene Einstellung
  „Zusage zählt als eingesetzt“, Standard: an).

## 7d. Fahrzeuge (optionales Modul)

- Modul an/aus; ist es aus, erscheint nirgends etwas von Fahrzeugen.
- **Fahrzeugquelle (genau wie beim Personal, geändert 16.10.2026):** „EÜDOK-Liste“ (Standard)
  **oder** „Divera247 (live)“ – keine Mischung.
  - Divera live: Fahrzeugliste wird live abgerufen (Name, Funkrufname, ggf. Kennzeichen);
    bei Ausfall der zuletzt abgerufene Stand als Puffer – kein Sync, keine doppelte Pflege.
    Die Fahrzeug-Seite in den Einstellungen zeigt dann nur „wird in Divera247 verwaltet“.
  - EÜDOK-Liste: Fahrzeuge werden in den Einstellungen gepflegt.
  - Aus einem Divera-Alarm werden die zugeordneten Fahrzeuge vorausgewählt; bei Quelle
    „EÜDOK-Liste“ über Bezeichnung bzw. Funkrufname zugeordnet.
- **Stammdaten selbst hinterlegter Fahrzeuge:** Bezeichnung, Funkrufname, Kennzeichen,
  aktiv/inaktiv
- **Fahrzeugfelder** werden in der Verwaltung konfiguriert (gleiches Prinzip wie die
  Personenfelder, Abschnitt 4) und gelten für alle Fahrzeuge – auch für Divera-Fahrzeuge,
  da Divera diese Felder nicht kennt.
- **UG-Drohne:** nur **„Kilometer“** (gefahrene km, ganze Zahl, **optional**) – kein
  Kilometerstand, keine Betriebsstunden, kein Fahrer.
- **Im Bericht:** Fahrzeuge als Kacheln antippen, darunter je Fahrzeug die Felder
  (Zahlentastatur, dürfen leer bleiben).
- **Im PDF:** Abschnitt „Eingesetzte Fahrzeuge“ (Fahrzeug, Funkrufname, Fahrzeugfelder –
  falls angegeben).
- **Im JSON:** je Fahrzeug ID, Bezeichnung, Herkunft (Divera / selbst hinterlegt) und die
  Feldwerte (leere Felder = `null`).

## 8. Ablage auf dem Server

```
euedok/
  public/                     Web-Root (in Plesk als Dokumentstamm eintragen,
                              dort heißt der Standard „httpdocs“) – nur Einstiegspunkte
    index.php                 Login, Startseite, Übersicht
    report.php                Berichtsformular
    quick-entry.php           QR-Schnellerfassung (Route /q/<key>)
    download.php              PDF/JSON/ZIP-Auslieferung (nur mit Berechtigung)
    settings.php              Einstellungsseite
    setup.php                 Einrichtungsassistent (nur beim ersten Aufruf)
    assets/
      css/  js/  icons/
  app/                        Programmlogik (PHP) – nicht per Web erreichbar
  vendor/                     Bibliotheken (PDF, Mail)
  data/                       alle Daten und Einstellungen – nicht per Web erreichbar,
                              wird bei Updates nie überschrieben
    settings/                 von EÜDOK selbst geschrieben – bearbeitet wird nur über
                              die Einstellungsseite im Browser
      unit.json               Einheit: Name, Farben, Kopf/Fußzeile, Module, Nummernformat
      master-data.json        Personal, Geräte, Fahrzeuge, Alarmierungsquellen
      custom-fields.json      Berichts-, Personen- und Fahrzeugfelder
      users.json              Zugänge (Passwort-Hashes, Rechte)
      qr-access.json          QR-Zugänge (Schlüssel-Hash, PIN-Hash, aktiv)
      mail.json               Mailversand (SMTP-Passwort verschlüsselt)
      divera.json             Divera247 (API-Schlüssel verschlüsselt)
    keys/                     bei der Einrichtung erzeugter Verschlüsselungsschlüssel
    cache/                    letzte Divera-Listen (Personal, Fahrzeuge) als Puffer
    logs/
      settings-audit.log      Änderungsprotokoll der Einstellungen
    branding/
      logo.png                (ab Release)
    reports/
      2026/
        Einsatzbericht_E-2026-003.json
        Einsatzbericht_E-2026-003.pdf
        Uebungsbericht_U-2026-016.json
```

Update = `public/`, `app/` und `vendor/` ersetzen. `data/` wird nie überschrieben.

## 8a. Namenskonvention

- **Technisch = Business-Englisch:** Ordner, Dateien, URLs/Routen, JSON-Schlüssel,
  IDs, Status- und Typwerte, Feld-IDs – kurz, sprechend, kleingeschrieben, Wörter mit
  Bindestrich (Dateien/Ordner/URLs) bzw. Unterstrich (JSON-Schlüssel/Feld-IDs).
- **Fachlich = Deutsch:** alles, was Menschen lesen – Oberfläche, Beschriftungen,
  Feldbezeichnungen (`label`), PDF-Inhalt, Mails, diese Dokumentation.
- **Begriffe:** Einsatz = `incident`, Übung = `exercise`, Bericht = `report`,
  Personal = `personnel`, Geräte = `equipment`, Fahrzeuge = `vehicles`,
  Alarmierung = `alert_source`, Einsatzleitung = `lead`, Zusatzfelder = `custom_fields`,
  Verlauf = `history`, Entwurf = `draft`, Abgeschlossen = `closed`,
  Divera-Termin = `event`, Schnellerfassung = `quick-entry`.
- **Einzige Ausnahme – Dateinamen der Berichte auf Deutsch**, weil sie per Mail
  verschickt und heruntergeladen werden: `<Berichtsart>bericht_<Berichtsnummer>`, z. B.
  `Einsatzbericht_E-2026-003.pdf` / `.json`, `Uebungsbericht_U-2026-016.pdf` / `.json`.
  Ohne Umlaute und Leerzeichen (Ü → Ue), damit jedes Mailprogramm sie sauber anzeigt.
  Die Bezeichnung kommt aus der Berichtsart, die Berichtsnummer (z. B. „E-2026-003“) aus
  dem einstellbaren Nummernformat der Einheit. Der Ordner bleibt englisch (`reports/`).

## 9. Datenformate (Entwurf)

### custom-fields.json (Auszug)

```json
{
  "report":  [],
  "person":  [
    { "id": "flight_minutes", "label": "Flugminuten", "type": "number", "unit": "min",
      "required": false, "show_total": true, "active": true }
  ],
  "vehicle": [
    { "id": "distance_km", "label": "Kilometer", "type": "number", "unit": "km",
      "required": false, "show_total": true, "active": true }
  ]
}
```

### master-data.json (Auszug)

```json
{
  "personnel": [
    { "id": "hadersbeck-f", "name": "Hadersbeck, Florian", "active": true },
    { "id": "beispiel-e", "name": "Beispiel, Erika", "active": true }
  ],
  "equipment": [
    { "id": "m30t-1", "label": "Drohne M30T - 1", "active": true }
  ],
  "vehicles": [
    { "id": "trailer-1", "label": "Anhänger UG-Drohne", "radio_call_sign": "",
      "license_plate": "LA-XX 456", "active": true }
  ],
  "alert_sources": [
    { "id": "ils", "label": "ILS Landshut" },
    { "id": "police", "label": "Polizei" }
  ]
}
```

### Bericht (`reports/2026/Einsatzbericht_E-2026-003.json`)

```json
{
  "schema_version": 1,
  "report_number": "E-2026-003",
  "type": { "id": "incident", "label": "Einsatz" },
  "start": "2026-10-12T21:40",
  "end": "2026-10-13T01:15",
  "duration_min": 215,
  "location": "Ergolding, Isarauen",
  "subject": "Vermisste Person",
  "alert_source": { "id": "ils", "label": "ILS Landshut" },
  "narrative": "…",
  "defects": "",
  "equipment": [
    { "id": "m30t-1", "label": "Drohne M30T - 1" },
    { "id": "powerstation", "label": "Powerstation" }
  ],
  "personnel": [
    { "id": "hadersbeck-f", "name": "Hadersbeck, Florian", "fields": { "flight_minutes": null } },
    { "id": "beispiel-e", "name": "Beispiel, Erika", "fields": { "flight_minutes": 85 } },
    { "id": "div-48211", "name": "Muster, Max", "divera_id": 48211,
      "fields": { "flight_minutes": 42 } }
  ],
  "lead": "hadersbeck-f",
  "vehicles": [
    { "id": "div-veh-311", "label": "MTW UG-Drohne", "source": "divera",
      "fields": { "distance_km": 37 } },
    { "id": "trailer-1", "label": "Anhänger UG-Drohne", "source": "local",
      "fields": { "distance_km": null } }
  ],
  "divera": { "type": "alarm", "id": 9912345, "title": "Vermisste Person",
              "text": "…", "imported_at": "2026-10-12T21:44:10" },
  "field_definitions": {
    "flight_minutes": { "label": "Flugminuten", "unit": "min" },
    "distance_km":    { "label": "Kilometer", "unit": "km" }
  },
  "custom_fields": {},
  "status": "closed",
  "history": [
    { "action": "created", "by": "hadersbeck", "at": "2026-10-13T08:12:00" },
    { "action": "closed",  "by": "hadersbeck", "at": "2026-10-13T08:30:00" },
    { "action": "mail_sent", "to": ["leitung@example.org"], "success": true,
      "at": "2026-10-13T08:30:04" }
  ]
}
```

- IDs **und** Bezeichnungen (`label`) werden gespeichert, damit Berichte auch nach
  Änderungen an den Stammdaten korrekt lesbar und auswertbar bleiben.
- Frei eingegebene Alarmierung: `{ "id": null, "label": "Bergwacht Landshut" }`
- Personal aus Divera247: ID `div-<Divera-Benutzer-ID>` plus `divera_id`; Fahrzeuge aus
  Divera entsprechend `div-veh-<ID>` mit `"source": "divera"`, eigene mit `"source": "local"`
- Feldwerte stehen je Person/Fahrzeug unter `fields`; `field_definitions` hält Bezeichnung
  und Einheit fest, damit der Bericht auch nach Änderungen an den Feldern lesbar bleibt
- `divera.type` ist bei Einsätzen `"alarm"`, bei Übungen `"event"` (Divera-Termin)
- `type.id`: `"incident"` (Einsatz) oder `"exercise"` (Übung); `status`: `"draft"` oder `"closed"`
- `vehicles` und `divera` fehlen, wenn das Modul bzw. die Anbindung nicht genutzt wird
- Über QR erstellt: `history`-Einträge tragen `"by": "qr-mtw", "source": "qr:mtw"` und
  zusätzlich `"created_by": "muster-m"` (gewählte Person)
- `schema_version` erlaubt spätere Format-Erweiterungen.

## 10. Sicherheit / Datenschutz

- Nur HTTPS, Login-Pflicht für alles inkl. PDF-Download – **einzige Ausnahme:**
  QR-Schnellerfassung, die nur das Erstellen eines neuen Berichts erlaubt (7b),
  optional zusätzlich mit PIN abgesichert
- Daten außerhalb des Web-Roots, zusätzlich `.htaccess`-Sperre als Rückfallebene
- Angemeldet bleiben auf dem eigenen Gerät (z. B. 30 Tage), damit am Einsatzort kein
  Passwort getippt werden muss; Abmelden jederzeit möglich
- Schutz gegen Brute-Force (Sperre nach Fehlversuchen), CSRF-Token
- Schreiben mit Datei-Sperre (zwei gleichzeitige Bearbeiter überschreiben sich nicht)
- Backup: ZIP-Export aller Berichte per Klick; zusätzlich liegt durch den Mailversand
  jeder Bericht automatisch ein zweites Mal im Empfänger-Postfach
- Alle Einstellungen nur über die Einstellungsseite (Recht „Einstellungen ändern“);
  jede Änderung wird protokolliert (wer, wann, was)
- SMTP-Passwort und Divera-API-Schlüssel werden im Browser eingegeben, serverseitig
  verschlüsselt gespeichert und nie wieder im Klartext angezeigt
- Divera-Abruf nur serverseitig und nur lesend; der Schlüssel erscheint nie im Browser,
  in Mails oder im JSON
- Hinweis: Mails werden zwischen den Servern per TLS übertragen, liegen aber im
  Postfach unverschlüsselt – Empfänger sollten dienstliche Postfächer sein

## 11. Ausbaustufen

**Stufe 1 – nutzbar für die UG-Drohne**
Login · Startseite · Formular (mobil optimiert) · JSON + PDF · automatischer
Mailversand · **QR-Schnellerfassung** inkl. Verwaltung und Druckvorlage ·
**Fahrzeug-Modul** · **Divera247: Einsatz- und Übungsdaten, Personal und Fahrzeuge live** ·
Übersicht · ZIP-Export · Zusatzfelder (Berichts-, Personen-, Fahrzeugfelder) ·
**Einstellungsseite im Browser für alle Einstellungen** (Einheit, Berichtsarten,
Pflichtfelder, Module, Zusatzfelder, Personal, Geräte, Fahrzeuge, Alarmierung,
Mailversand, Divera247, Zugänge, QR-Zugänge) · **Einrichtungsassistent** ·
Startwerte UG-Drohne (5 Dummy-Personen, Flugminuten, Kilometer)

**Stufe 2 – Release für andere Einheiten**
Logo-Upload · Beispiel-Startwerte „Feuerwehr“ im Einrichtungsassistenten ·
Einstellungen sichern / auf eine andere Installation übertragen (Export/Import) ·
Installationsanleitung

**Stufe 3 – optional**
Anhänge (Fotos, Flug-Logs) · Import alter Berichte · Ablage der PDFs in Nextcloud
(WebDAV) · Standort per GPS übernehmen

## 12. Offene Punkte

Keine offenen Konzeptfragen.

**Später nachzureichen (blockiert die Umsetzung nicht):**
- Empfängeradresse(n) und SMTP-Absender-Postfach – bis dahin ist der Mailversand in
  den Einstellungen ausgeschaltet
- Logo (zum Release)
- Divera247-API-Schlüssel (Testzugang) – bis dahin ist die Anbindung aus
- Selbst hinterlegte Fahrzeuge, die nicht in Divera sind (falls es welche gibt)
- Echte Personaldaten (bis dahin 5 Dummy-Datensätze)

Umsetzung erst nach ausdrücklicher Freigabe.
