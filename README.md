# Urlaubskasse

Web-App zum Abrechnen von Urlaubsgruppen-Ausgaben – https://urlaubskasse.mapa-ai.de

- Organisator registriert sich, legt eine Reise und die Teilnehmer an und teilt **einen Link** (`/r/{token}`) mit der Gruppe.
- Wer den Link hat, wählt seinen Namen und trägt Ausgaben und Übernachtungen ein – ohne Anmeldung.
  Teilnehmer anlegen/entfernen, Reise bearbeiten und Link erneuern kann nur der Organisator.
- Die App berechnet Gesamtkosten, Übernachtungen, Ausgaben je Person, Kosten pro Person & Nacht,
  den Anteil jeder Person (nach Übernachtungen), offene Beträge und **wer wem wieviel überweist**.

## Technik
PHP 8 + MySQL (PDO), Vanilla-JS-Frontend, kein Build-Schritt. Das Repo-Root ist das Webroot.
Die Tabellen werden beim ersten Aufruf automatisch angelegt.

```
index.html, assets/      Frontend (SPA)
api.php                  JSON-API
lib/settlement.php       Abrechnungslogik (Cent-genau, Largest-Remainder-Rundung, Greedy-Ausgleich)
lib/db.php, lib/auth.php DB-Zugriff, Schema, Sessions, Rate-Limiting
tests/                   php tests/settlement_test.php
```

## Deployment auf Hostinger
1. Subdomain `urlaubskasse.mapa-ai.de` anlegen, SSL aktivieren.
2. MySQL-Datenbank + Benutzer anlegen.
3. hPanel → *Git*: Repository `mapa-webdesign/urlaubskasse`, Branch `main`, Zielordner = Webroot der Subdomain (Auto-Deployment per Webhook optional).
4. `https://urlaubskasse.mapa-ai.de/setup.php` aufrufen, DB-Name, DB-Benutzer und Passwort eintragen.
   Die Seite testet die Verbindung, legt die Tabellen an und schreibt `config.php`
   (nicht versioniert, bleibt bei Deployments erhalten). Sobald die Verbindung funktioniert, ist `setup.php` gesperrt.
   Alternativ `config.sample.php` manuell nach `config.php` kopieren.
5. Registrieren, fertig.

## Lokal entwickeln
```
cp config.sample.php config.php   # db_dsn auf die SQLite-Zeile umstellen
php -S localhost:8000 dev-router.php
php tests/settlement_test.php
```
