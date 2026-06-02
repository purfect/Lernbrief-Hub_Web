# Lernbrief-Hub PHP

Webroot: dieses Verzeichnis `lernhub_webroot`.

Datenbank: `../lernbrief_hub.db`, also eine Ebene ueber dem Webroot und nicht direkt per Browser erreichbar.

Temporäre Exportdateien: `../tmp/mpdf`, ebenfalls eine Ebene ueber dem Webroot.

Voraussetzungen:

- PHP 8.1 oder neuer
- `pdo_sqlite`
- optional `zip` fuer echten DOCX-Export; ohne `zip` wird ein Word-kompatibles HTML-Dokument ausgeliefert
- optional `dom` fuer die beste HTML-Auswertung beim Export
- empfohlen: Composer-Pakete `mpdf/mpdf` und `phpoffice/phpword` fuer deutlich bessere PDF-/DOCX-Exporte

Editor/Export:

- Der Lernbrief-Editor ist ein paketfreier Rich-Text-Editor auf Basis von `contenteditable`.
- Unterstuetzt werden u. a. Absatz/Ueberschrift, fett, kursiv, unterstrichen, Listen, Ausrichtung, Links, Schriftart und Schriftgroesse.
- Mit Composer-Paketen: PDF wird ueber mPDF erzeugt, DOCX ueber PHPWord.
- Ohne Composer-Pakete: einfache eingebaute Export-Fallbacks bleiben aktiv.

Datenverwaltung:

- Lerngruppen koennen in `Daten & Archiv` deaktiviert werden.
- Deaktivierte Lerngruppen verschwinden aus Dashboard, Suche und Uebersicht.
- Schueler aus deaktivierten Lerngruppen zaehlen nicht mehr in den Statistik-Kennzahlen der Uebersicht.
- Beim Deaktivieren wird der interne Gruppenname mit der ID ergaenzt, damit derselbe Name spaeter wieder fuer eine neue Lerngruppe verwendet werden kann.

Ubuntu 24.04 mit PHP 8.3:

```bash
sudo apt update
sudo apt install php8.3 php8.3-cli php8.3-sqlite3 php8.3-xml php8.3-zip php8.3-mbstring php8.3-gd composer
```

Composer-Pakete installieren, eine Ebene ueber dem Webroot:

```bash
cd /pfad/zu/lerbriefe-hub
composer install
```

Wenn Apache/Nginx mit `www-data` laeuft, Schreibrechte fuer DB und Temp-Verzeichnis setzen:

```bash
cd /pfad/zu/lerbriefe-hub
sudo mkdir -p tmp/mpdf
sudo chown -R www-data:www-data lernbrief_hub.db tmp
sudo chmod -R 775 tmp
```

Bei Caddy mit PHP-FPM laeuft PHP meistens ebenfalls als `www-data`. Falls ein anderer User genutzt wird:

```bash
ps aux | grep php-fpm
```

PDF-Export-Debug ohne Webserver-Logs:

```bash
cd /pfad/zu/lerbriefe-hub
tail -n 80 tmp/export-debug.log
```

Lokaler Start, wenn PHP im PATH ist:

```powershell
php8.3 -S 127.0.0.1:8080 -t lernhub_webroot
```

Danach im Browser `http://127.0.0.1:8080/` oeffnen.
