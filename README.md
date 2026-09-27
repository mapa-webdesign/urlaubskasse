# Urlaubskasse

Web-App zum Abrechnen von Urlaubsgruppen-Ausgaben – https://urlaubskasse.mapa-ai.de

- Organisator registriert sich, legt eine Reise an und lädt Mitreisende mit **eigenem Link + Passwort** ein.
- Alle tragen ihre Ausgaben und Übernachtungen ein (Teilnehmer nur die eigenen, Organisator alle).
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
4. Im Dateimanager `config.sample.php` nach `config.php` kopieren und DB-Zugangsdaten eintragen
   (`config.php` ist nicht versioniert und bleibt bei Deployments erhalten).
5. Seite aufrufen, registrieren, fertig.

## Lokal entwickeln
```
cp config.sample.php config.php   # db_dsn auf die SQLite-Zeile umstellen
php -S localhost:8000 dev-router.php
php tests/settlement_test.php
```
