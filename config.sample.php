<?php
// Kopie als config.php anlegen (wird nicht versioniert) und Zugangsdaten eintragen.
return [
    // Hostinger MySQL:
    'db_dsn'  => 'mysql:host=localhost;dbname=u000000000_urlaubskasse;charset=utf8mb4',
    'db_user' => 'u000000000_urlaubskasse',
    'db_pass' => 'PASSWORT',
    // Zur Fehlersuche vorübergehend auf true setzen (zeigt technische Fehlermeldungen an):
    'debug'   => false,
    // Lokal/Tests alternativ SQLite:
    // 'db_dsn' => 'sqlite:' . __DIR__ . '/data/urlaubskasse.sqlite',
];
