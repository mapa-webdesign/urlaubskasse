<?php
declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/settlement.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['error' => $msg], $code);
}

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    $msg = 'Serverfehler. Bitte später erneut versuchen.';
    if (str_contains($e->getMessage(), 'config.php')) {
        $msg = $e->getMessage();
    } elseif ($e instanceof PDOException && !db_connected()) {
        $msg = 'Keine Verbindung zur Datenbank – bitte Zugangsdaten unter /setup.php prüfen.';
    }
    try {
        if (!empty(config()['debug'])) $msg .= ' [' . get_class($e) . ': ' . $e->getMessage() . ']';
    } catch (Throwable) {
    }
    out(['error' => $msg], 500);
});

// ---- Eingabe-Helfer ----------------------------------------------------

function str_in(array $in, string $key, int $max, bool $required = true): string
{
    $v = trim((string)($in[$key] ?? ''));
    if ($required && $v === '') fail("Feld „{$key}“ fehlt.");
    if (mb_strlen($v) > $max) fail("Feld „{$key}“ ist zu lang (max. {$max} Zeichen).");
    return $v;
}

function int_in(array $in, string $key, int $min = 0, int $max = 1000): int
{
    $v = $in[$key] ?? null;
    if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) {
        fail("Ungültiger Wert für „{$key}“.");
    }
    return (int)$v;
}

function date_in(array $in, string $key): ?string
{
    $v = trim((string)($in[$key] ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) fail("Ungültiges Datum für „{$key}“.");
    return $v;
}

/** "1.234,56" / "1234.56" / "12" -> Cent */
function amount_in(array $in, string $key): int
{
    $v = str_replace([' ', '€'], '', trim((string)($in[$key] ?? '')));
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    $v = str_replace(',', '.', $v);
    if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $v, $m)) {
        fail('Ungültiger Betrag.');
    }
    $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
    if ($cents <= 0) fail('Betrag muss größer als 0 sein.');
    return $cents;
}

function fetch_one(string $sql, array $params): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}

function participant_in_trip(int $pid, int $tripId): array
{
    $p = fetch_one('SELECT * FROM participants WHERE id = ? AND trip_id = ?', [$pid, $tripId]);
    if (!$p) fail('Teilnehmer nicht gefunden.', 404);
    return $p;
}

function expense_in_trip(int $eid): array
{
    $e = fetch_one('SELECT * FROM expenses WHERE id = ?', [$eid]);
    if (!$e) fail('Ausgabe nicht gefunden.', 404);
    return $e;
}

function create_participant(int $tripId, string $name, int $nights, bool $owner): int
{
    // token/password_hash werden nicht mehr genutzt (Zugang über Reise-Link), Spalten sind aber NOT NULL.
    db()->prepare('INSERT INTO participants (trip_id, name, nights, token, password_hash, is_owner, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tripId, $name, $nights, generate_token(), '', $owner ? 1 : 0, now()]);
    return (int)db()->lastInsertId();
}

function trip_payload(int $tripId): array
{
    $acc = trip_access($tripId);
    $isOwner = $acc['role'] === 'owner';

    $st = db()->prepare('SELECT id, name, nights, is_owner FROM participants WHERE trip_id = ? ORDER BY is_owner DESC, name');
    $st->execute([$tripId]);
    $participants = array_map(fn($p) => [
        'id' => (int)$p['id'],
        'name' => $p['name'],
        'nights' => (int)$p['nights'],
        'is_owner' => (bool)$p['is_owner'],
    ], $st->fetchAll());

    $st = db()->prepare('SELECT id, participant_id, description, amount_cents, expense_date FROM expenses WHERE trip_id = ? ORDER BY COALESCE(expense_date, created_at) DESC, id DESC');
    $st->execute([$tripId]);
    $expenses = array_map(fn($e) => [
        'id' => (int)$e['id'],
        'participant_id' => (int)$e['participant_id'],
        'description' => $e['description'],
        'amount_cents' => (int)$e['amount_cents'],
        'expense_date' => $e['expense_date'],
    ], $st->fetchAll());

    $t = $acc['trip'];
    return [
        'trip' => [
            'id' => (int)$t['id'],
            'name' => $t['name'],
            'start_date' => $t['start_date'],
            'end_date' => $t['end_date'],
            'share_token' => $isOwner ? $t['share_token'] : null,
        ],
        'role' => $acc['role'],
        'participants' => $participants,
        'expenses' => $expenses,
        'settlement' => settle($participants, $expenses),
    ];
}

// ---- Routing -----------------------------------------------------------

start_session();
$action = (string)($_GET['action'] ?? '');
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($in)) fail('Ungültige Anfrage.');
    check_csrf();
} elseif (!in_array($action, ['me', 'trips.list', 'trips.get'], true)) {
    fail('Methode nicht erlaubt.', 405);
}

switch ($action) {
    case 'me':
        $user = null;
        if ($uid = current_user_id()) {
            $user = fetch_one('SELECT id, name, email FROM users WHERE id = ?', [$uid]);
            if (!$user) unset($_SESSION['user_id']);
        }
        out(['csrf' => $_SESSION['csrf'], 'user' => $user]);

    case 'register':
        $name = str_in($in, 'name', 100);
        $email = mb_strtolower(str_in($in, 'email', 190));
        $pw = (string)($in['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Ungültige E-Mail-Adresse.');
        if (strlen($pw) < 8) fail('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if (fetch_one('SELECT id FROM users WHERE email = ?', [$email])) fail('Diese E-Mail-Adresse ist bereits registriert.');
        db()->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), now()]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)db()->lastInsertId();
        out(['ok' => true]);

    case 'login':
        $key = 'login:' . client_ip();
        rate_limit_check($key);
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $user = fetch_one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify((string)($in['password'] ?? ''), $user['password_hash'])) {
            rate_limit_hit($key);
            fail('E-Mail oder Passwort falsch.', 401);
        }
        rate_limit_clear($key);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        out(['ok' => true]);

    case 'logout':
        $_SESSION = [];
        session_regenerate_id(true);
        out(['ok' => true]);

    case 'trips.list':
        $uid = require_user();
        $st = db()->prepare('SELECT t.id, t.name, t.start_date, t.end_date,
                (SELECT COUNT(*) FROM participants p WHERE p.trip_id = t.id) AS participants,
                (SELECT COALESCE(SUM(amount_cents), 0) FROM expenses e WHERE e.trip_id = t.id) AS total_cents
            FROM trips t WHERE t.owner_user_id = ? ORDER BY t.created_at DESC, t.id DESC');
        $st->execute([$uid]);
        out(['trips' => array_map(fn($t) => [
            'id' => (int)$t['id'], 'name' => $t['name'], 'start_date' => $t['start_date'], 'end_date' => $t['end_date'],
            'participants' => (int)$t['participants'], 'total_cents' => (int)$t['total_cents'],
        ], $st->fetchAll())]);

    case 'trips.create':
        $uid = require_user();
        $user = fetch_one('SELECT name FROM users WHERE id = ?', [$uid]);
        db()->beginTransaction();
        db()->prepare('INSERT INTO trips (owner_user_id, name, start_date, end_date, share_token, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, str_in($in, 'name', 150), date_in($in, 'start_date'), date_in($in, 'end_date'), generate_token(), now()]);
        $tripId = (int)db()->lastInsertId();
        create_participant($tripId, $user['name'], int_in($in + ['nights' => 0], 'nights'), true);
        db()->commit();
        out(['id' => $tripId]);

    case 'trips.get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id && share_token_in() !== '') {
            $t = fetch_one('SELECT id FROM trips WHERE share_token = ?', [share_token_in()]);
            if (!$t) fail('Dieser Reise-Link ist ungültig oder wurde erneuert.', 404);
            $id = (int)$t['id'];
        }
        out(trip_payload($id));

    case 'trips.update':
        $acc = trip_access((int)($in['id'] ?? 0));
        require_owner($acc);
        db()->prepare('UPDATE trips SET name = ?, start_date = ?, end_date = ? WHERE id = ?')
            ->execute([str_in($in, 'name', 150), date_in($in, 'start_date'), date_in($in, 'end_date'), $acc['trip']['id']]);
        out(trip_payload((int)$acc['trip']['id']));

    case 'trips.resetShare':
        $acc = trip_access((int)($in['id'] ?? 0));
        require_owner($acc);
        db()->prepare('UPDATE trips SET share_token = ? WHERE id = ?')->execute([generate_token(), $acc['trip']['id']]);
        out(trip_payload((int)$acc['trip']['id']));

    case 'trips.delete':
        $acc = trip_access((int)($in['id'] ?? 0));
        require_owner($acc);
        $tid = (int)$acc['trip']['id'];
        db()->beginTransaction();
        db()->prepare('DELETE FROM expenses WHERE trip_id = ?')->execute([$tid]);
        db()->prepare('DELETE FROM participants WHERE trip_id = ?')->execute([$tid]);
        db()->prepare('DELETE FROM trips WHERE id = ?')->execute([$tid]);
        db()->commit();
        out(['ok' => true]);

    case 'participants.create':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        require_owner($acc);
        create_participant((int)$acc['trip']['id'], str_in($in, 'name', 100), int_in($in, 'nights'), false);
        out(trip_payload((int)$acc['trip']['id']));

    case 'participants.update':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        $p = participant_in_trip((int)($in['id'] ?? 0), (int)$acc['trip']['id']);
        $name = $acc['role'] === 'owner' ? str_in($in, 'name', 100) : $p['name'];
        db()->prepare('UPDATE participants SET name = ?, nights = ? WHERE id = ?')
            ->execute([$name, int_in($in, 'nights'), $p['id']]);
        out(trip_payload((int)$acc['trip']['id']));

    case 'participants.delete':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        require_owner($acc);
        $p = participant_in_trip((int)($in['id'] ?? 0), (int)$acc['trip']['id']);
        if ($p['is_owner']) fail('Der Organisator kann nicht entfernt werden.');
        db()->beginTransaction();
        db()->prepare('DELETE FROM expenses WHERE participant_id = ?')->execute([$p['id']]);
        db()->prepare('DELETE FROM participants WHERE id = ?')->execute([$p['id']]);
        db()->commit();
        out(trip_payload((int)$acc['trip']['id']));

    case 'expenses.create':
    case 'expenses.update':
        if ($action === 'expenses.update') {
            $existing = expense_in_trip((int)($in['id'] ?? 0));
            $tripId = (int)$existing['trip_id'];
        } else {
            $existing = null;
            $tripId = (int)($in['trip_id'] ?? 0);
        }
        $acc = trip_access($tripId);
        $payer = (int)participant_in_trip((int)($in['participant_id'] ?? 0), $tripId)['id'];
        $values = [$payer, str_in($in, 'description', 200), amount_in($in, 'amount'), date_in($in, 'expense_date')];
        if ($existing) {
            db()->prepare('UPDATE expenses SET participant_id = ?, description = ?, amount_cents = ?, expense_date = ? WHERE id = ?')
                ->execute([...$values, $existing['id']]);
        } else {
            db()->prepare('INSERT INTO expenses (participant_id, description, amount_cents, expense_date, trip_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([...$values, $tripId, now()]);
        }
        out(trip_payload($tripId));

    case 'expenses.delete':
        $e = expense_in_trip((int)($in['id'] ?? 0));
        trip_access((int)$e['trip_id']);
        db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([$e['id']]);
        out(trip_payload((int)$e['trip_id']));

    default:
        fail('Unbekannte Aktion.', 404);
}
