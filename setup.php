<?php
declare(strict_types=1);

// Einrichtung: DB-Zugangsdaten eintragen und config.php schreiben.
// Nur verfügbar, solange keine funktionierende Datenbankverbindung konfiguriert ist.

require __DIR__ . '/lib/db.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
session_name('uksetup');
session_start();
$_SESSION['setup_csrf'] ??= bin2hex(random_bytes(16));

$configFile = __DIR__ . '/config.php';

/** Prüft, ob die aktuelle config.php eine funktionierende Verbindung liefert. */
function current_config_works(string $file): bool
{
    if (!is_file($file)) return false;
    try {
        $cfg = require $file;
        if (!is_array($cfg) || empty($cfg['db_dsn'])) return false;
        db_connect($cfg['db_dsn'], $cfg['db_user'] ?? null, $cfg['db_pass'] ?? null);
        return true;
    } catch (Throwable) {
        return false;
    }
}

$locked = current_config_works($configFile);
$error = null;
$done = false;
$values = ['db_name' => '', 'db_user' => ''];

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['db_name'] = trim((string)($_POST['db_name'] ?? ''));
    $values['db_user'] = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');

    if (!hash_equals($_SESSION['setup_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Sitzung abgelaufen – bitte Seite neu laden.';
    } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_name']) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $values['db_user'])) {
        $error = 'DB-Name und DB-Benutzer dürfen nur Buchstaben, Ziffern und _ enthalten.';
    } else {
        $dsn = 'mysql:host=localhost;dbname=' . $values['db_name'] . ';charset=utf8mb4';
        try {
            $pdo = db_connect($dsn, $values['db_user'], $pass);
            ensure_schema($pdo);
            $cfg = ['db_dsn' => $dsn, 'db_user' => $values['db_user'], 'db_pass' => $pass, 'debug' => false];
            $php = "<?php\n// Erzeugt durch setup.php am " . date('Y-m-d H:i') . "\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
                $error = 'Verbindung ok, aber config.php konnte nicht geschrieben werden (Dateirechte prüfen).';
            } else {
                @chmod($configFile, 0600);
                if (function_exists('opcache_invalidate')) opcache_invalidate($configFile, true);
                $done = true;
            }
        } catch (PDOException $e) {
            sleep(1); // Durchprobieren bremsen
            $code = $e->errorInfo[1] ?? $e->getCode();
            $error = match ((int)$code) {
                1045 => 'Zugriff verweigert – DB-Benutzer oder Passwort falsch.',
                1044, 1049 => 'Datenbank nicht gefunden oder Benutzer hat keinen Zugriff darauf.',
                2002 => 'MySQL-Server nicht erreichbar.',
                default => 'Verbindung fehlgeschlagen: ' . $e->getMessage(),
            };
        }
    }
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Einrichtung – Urlaubskasse</title>
  <link rel="icon" href="assets/favicon-32.png?v=8" sizes="32x32" type="image/png">
  <link rel="icon" href="assets/logo.png?v=8" sizes="192x192" type="image/png">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=8">
  <meta name="apple-mobile-web-app-title" content="Urlaubskasse">
  <link rel="stylesheet" href="assets/style.css?v=8">
</head>
<body>
  <header class="topbar"><a href="./" class="brand"><img src="assets/logo.png?v=8" alt="" width="30" height="30"> Urlaubskasse</a></header>
  <main class="container">
    <div class="card narrow">
      <p class="eyebrow">Einrichtung</p>
<?php if ($done): ?>
      <h1>Fertig!</h1>
      <div class="callout ok">Die Datenbankverbindung funktioniert, die Tabellen sind angelegt und <code>config.php</code> wurde gespeichert.</div>
      <a class="btn primary" href="./#/register">Jetzt registrieren</a>
<?php elseif ($locked): ?>
      <h1>Bereits eingerichtet</h1>
      <p>Die Datenbankverbindung funktioniert. Aus Sicherheitsgründen ist die Einrichtung gesperrt. Zum Ändern <code>config.php</code> im Dateimanager bearbeiten oder löschen.</p>
      <a class="btn primary" href="./">Zur Urlaubskasse</a>
<?php else: ?>
      <h1>Datenbank verbinden</h1>
      <p class="muted small">Die Werte findest du in hPanel unter <strong>Datenbanken → Verwaltung</strong> (inkl. Präfix, z.&nbsp;B. <code>u123456789_urlaubskasse</code>).</p>
      <?php if ($error): ?><div class="callout warn"><?= $e($error) ?></div><?php endif; ?>
      <form method="post" class="form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $e($_SESSION['setup_csrf']) ?>">
        <label>DB-Name<input name="db_name" required maxlength="64" value="<?= $e($values['db_name']) ?>" placeholder="u123456789_urlaubskasse"></label>
        <label>DB-Benutzer<input name="db_user" required maxlength="64" value="<?= $e($values['db_user']) ?>" placeholder="u123456789_urlaubskasse"></label>
        <label>DB-Passwort<input name="db_pass" type="password" required autocomplete="new-password"></label>
        <button type="submit" class="btn primary">Verbindung testen &amp; speichern</button>
      </form>
<?php endif; ?>
    </div>
  </main>
</body>
</html>
