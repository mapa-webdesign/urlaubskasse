<?php
declare(strict_types=1);

function start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('uksess');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 60,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 60));
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $sent)) {
        fail('Sitzung abgelaufen – bitte Seite neu laden.', 403);
    }
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Max. 10 Fehlversuche in 15 Minuten pro Schlüssel. */
function rate_limit_check(string $key): void
{
    $since = time() - 900;
    db()->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([$since]);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE k = ?');
    $st->execute([$key]);
    if ((int)$st->fetchColumn() >= 10) {
        fail('Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.', 429);
    }
}

function rate_limit_hit(string $key): void
{
    db()->prepare('INSERT INTO login_attempts (k, ts) VALUES (?, ?)')->execute([$key, time()]);
}

function rate_limit_clear(string $key): void
{
    db()->prepare('DELETE FROM login_attempts WHERE k = ?')->execute([$key]);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function require_user(): int
{
    $uid = current_user_id();
    if ($uid === null) fail('Bitte anmelden.', 401);
    return $uid;
}

function generate_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Share-Token aus dem Request (Header oder Query). */
function share_token_in(): string
{
    return (string)($_SERVER['HTTP_X_SHARE_TOKEN'] ?? $_GET['share'] ?? '');
}

/**
 * Prüft den Zugriff auf eine Reise: Organisator (Session) oder Gast (gültiger Reise-Link).
 * @return array{trip: array, role: string}
 */
function trip_access(int $tripId): array
{
    $st = db()->prepare('SELECT * FROM trips WHERE id = ?');
    $st->execute([$tripId]);
    $trip = $st->fetch();
    if (!$trip) fail('Reise nicht gefunden.', 404);

    $uid = current_user_id();
    if ($uid !== null && (int)$trip['owner_user_id'] === $uid) {
        return ['trip' => $trip, 'role' => 'owner'];
    }
    $share = share_token_in();
    if ($share !== '' && $trip['share_token'] !== null && hash_equals($trip['share_token'], $share)) {
        return ['trip' => $trip, 'role' => 'guest'];
    }
    fail('Kein Zugriff auf diese Reise. Ist der Link noch aktuell?', 403);
}

function require_owner(array $acc): void
{
    if ($acc['role'] !== 'owner') fail('Das darf nur der Organisator.', 403);
}
