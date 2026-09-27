<?php
// Nur für lokale Entwicklung: php -S localhost:8000 dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/r/([a-f0-9]{32})/?$#', $path, $m)) {
    header('Location: /#/r/' . $m[1], true, 302);
    return true;
}
if (preg_match('#^/(lib|tests|data)/|^/config#', $path)) {
    http_response_code(403);
    return true;
}
return false;
