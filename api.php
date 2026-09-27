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
    out(['error' => 'Serverfehler. Bitte später erneut versuchen.'], 500);
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

function create_participant(int $tripId, string $name, int $nights, bool $owner): array
{
    $pw = generate_password();
    $token = generate_token();
    db()->prepare('INSERT INTO participants (trip_id, name, nights, token, password_hash, is_owner, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tripId, $name, $nights, $token, password_hash($pw, PASSWORD_DEFAULT), $owner ? 1 : 0, now()]);
    return ['id' => (int)db()->lastInsertId(), 'token' => $token, 'password' => $pw];
}

function trip_payload(int $tripId): array
{
    $acc = trip_access($tripId);
    $isOwner = $acc['role'] === 'owner';

    $st = db()->prepare('SELECT id, name, nights, token, is_owner FROM participants WHERE trip_id = ? ORDER BY is_owner DESC, name');
    $st->execute([$tripId]);
    $participants = [];
    foreach ($st->fetchAll() as $p) {
        $participants[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'nights' => (int)$p['nights'],
            'is_owner' => (bool)$p['is_owner'],
            'token' => $isOwner ? $p['token'] : null,
        ];
    }

    $st = db()->prepare('SELECT id, participant_id, description, amount_cents, expense_date, created_at FROM expenses WHERE trip_id = ? ORDER BY COALESCE(expense_date, created_at) DESC, id DESC');
    $st->execute([$tripId]);
    $expenses = array_map(fn($e) => [
        'id' => (int)$e['id'],
        'participant_id' => (int)$e['participant_id'],
        'description' => $e['description'],
        'amount_cents' => (int)$e['amount_cents'],
        'expense_date' => $e['expense_date'],
    ], $st->fetchAll());

    return [
        'trip' => [
            'id' => (int)$acc['trip']['id'],
            'name' => $acc['trip']['name'],
            'start_date' => $acc['trip']['start_date'],
            'end_date' => $acc['trip']['end_date'],
        ],
        'role' => $acc['role'],
        'me_id' => $acc['me'] ? (int)$acc['me']['id'] : null,
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
} elseif (!in_array($action, ['me', 'trips.list', 'trips.get', 'join.info'], true)) {
    fail('Methode nicht erlaubt.', 405);
}

switch ($action) {
    case 'me':
        $user = null;
        if ($uid = current_user_id()) {
            $user = fetch_one('SELECT id, name, email FROM users WHERE id = ?', [$uid]);
            if (!$user) unset($_SESSION['user_id']);
        }
        $joined = [];
        foreach ($_SESSION['joined'] as $tid => $pid) {
            $row = fetch_one('SELECT t.id, t.name AS trip_name, p.name FROM participants p JOIN trips t ON t.id = p.trip_id WHERE p.id = ? AND t.id = ?', [$pid, $tid]);
            if ($row) $joined[] = ['trip_id' => (int)$row['id'], 'trip_name' => $row['trip_name'], 'name' => $row['name']];
        }
        out(['csrf' => $_SESSION['csrf'], 'user' => $user, 'joined' => $joined]);

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

    case 'join.info':
        $p = fetch_one('SELECT p.name, t.name AS trip_name FROM participants p JOIN trips t ON t.id = p.trip_id WHERE p.token = ?', [(string)($_GET['token'] ?? '')]);
        if (!$p) fail('Einladungslink ungültig.', 404);
        out(['name' => $p['name'], 'trip_name' => $p['trip_name']]);

    case 'join':
        $key = 'join:' . client_ip();
        rate_limit_check($key);
        $p = fetch_one('SELECT id, trip_id, password_hash FROM participants WHERE token = ?', [(string)($in['token'] ?? '')]);
        if (!$p || !password_verify((string)($in['password'] ?? ''), $p['password_hash'])) {
            rate_limit_hit($key);
            fail('Passwort falsch.', 401);
        }
        rate_limit_clear($key);
        session_regenerate_id(true);
        $_SESSION['joined'][(int)$p['trip_id']] = (int)$p['id'];
        out(['trip_id' => (int)$p['trip_id']]);

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
        db()->prepare('INSERT INTO trips (owner_user_id, name, start_date, end_date, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$uid, str_in($in, 'name', 150), date_in($in, 'start_date'), date_in($in, 'end_date'), now()]);
        $tripId = (int)db()->lastInsertId();
        create_participant($tripId, $user['name'], int_in($in + ['nights' => 0], 'nights'), true);
        db()->commit();
        out(['id' => $tripId]);

    case 'trips.get':
        out(trip_payload((int)($_GET['id'] ?? 0)));

    case 'trips.update':
        $acc = trip_access((int)($in['id'] ?? 0));
        if ($acc['role'] !== 'owner') fail('Nur der Organisator darf die Reise bearbeiten.', 403);
        db()->prepare('UPDATE trips SET name = ?, start_date = ?, end_date = ? WHERE id = ?')
            ->execute([str_in($in, 'name', 150), date_in($in, 'start_date'), date_in($in, 'end_date'), $acc['trip']['id']]);
        out(trip_payload((int)$acc['trip']['id']));

    case 'trips.delete':
        $acc = trip_access((int)($in['id'] ?? 0));
        if ($acc['role'] !== 'owner') fail('Nur der Organisator darf die Reise löschen.', 403);
        $tid = (int)$acc['trip']['id'];
        db()->beginTransaction();
        db()->prepare('DELETE FROM expenses WHERE trip_id = ?')->execute([$tid]);
        db()->prepare('DELETE FROM participants WHERE trip_id = ?')->execute([$tid]);
        db()->prepare('DELETE FROM trips WHERE id = ?')->execute([$tid]);
        db()->commit();
        out(['ok' => true]);

    case 'participants.create':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        if ($acc['role'] !== 'owner') fail('Nur der Organisator darf Teilnehmer einladen.', 403);
        $created = create_participant((int)$acc['trip']['id'], str_in($in, 'name', 100), int_in($in, 'nights'), false);
        out(['invite' => $created] + trip_payload((int)$acc['trip']['id']));

    case 'participants.update':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        $p = participant_in_trip((int)($in['id'] ?? 0), (int)$acc['trip']['id']);
        if ($acc['role'] !== 'owner' && (int)$p['id'] !== (int)$acc['me']['id']) {
            fail('Du kannst nur deine eigenen Angaben ändern.', 403);
        }
        db()->prepare('UPDATE participants SET name = ?, nights = ? WHERE id = ?')
            ->execute([str_in($in, 'name', 100), int_in($in, 'nights'), $p['id']]);
        out(trip_payload((int)$acc['trip']['id']));

    case 'participants.resetPassword':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        if ($acc['role'] !== 'owner') fail('Nur der Organisator darf Passwörter zurücksetzen.', 403);
        $p = participant_in_trip((int)($in['id'] ?? 0), (int)$acc['trip']['id']);
        $pw = generate_password();
        db()->prepare('UPDATE participants SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $p['id']]);
        out(['invite' => ['id' => (int)$p['id'], 'token' => $p['token'], 'password' => $pw]]);

    case 'participants.delete':
        $acc = trip_access((int)($in['trip_id'] ?? 0));
        if ($acc['role'] !== 'owner') fail('Nur der Organisator darf Teilnehmer entfernen.', 403);
        $p = participant_in_trip((int)($in['id'] ?? 0), (int)$acc['trip']['id']);
        if ($p['is_owner']) fail('Der Organisator kann nicht entfernt werden.');
        db()->beginTransaction();
        db()->prepare('DELETE FROM expenses WHERE participant_id = ?')->execute([$p['id']]);
        db()->prepare('DELETE FROM participants WHERE id = ?')->execute([$p['id']]);
        db()->commit();
        out(trip_payload((int)$acc['trip']['id']));

    case 'expenses.create':
    case 'expenses.update':
        $isUpdate = $action === 'expenses.update';
        if ($isUpdate) {
            $existing = expense_in_trip((int)($in['id'] ?? 0));
            $acc = trip_access((int)$existing['trip_id']);
            if ($acc['role'] !== 'owner' && (int)$existing['participant_id'] !== (int)$acc['me']['id']) {
                fail('Du kannst nur deine eigenen Ausgaben ändern.', 403);
            }
        } else {
            $acc = trip_access((int)($in['trip_id'] ?? 0));
        }
        $tripId = (int)$acc['trip']['id'];
        $payer = $acc['role'] === 'owner' && isset($in['participant_id'])
            ? (int)participant_in_trip((int)$in['participant_id'], $tripId)['id']
            : (int)($isUpdate ? $existing['participant_id'] : $acc['me']['id']);
        $values = [$payer, str_in($in, 'description', 200), amount_in($in, 'amount'), date_in($in, 'expense_date')];
        if ($isUpdate) {
            db()->prepare('UPDATE expenses SET participant_id = ?, description = ?, amount_cents = ?, expense_date = ? WHERE id = ?')
                ->execute([...$values, $existing['id']]);
        } else {
            db()->prepare('INSERT INTO expenses (participant_id, description, amount_cents, expense_date, trip_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([...$values, $tripId, now()]);
        }
        out(trip_payload($tripId));

    case 'expenses.delete':
        $e = expense_in_trip((int)($in['id'] ?? 0));
        $acc = trip_access((int)$e['trip_id']);
        if ($acc['role'] !== 'owner' && (int)$e['participant_id'] !== (int)$acc['me']['id']) {
            fail('Du kannst nur deine eigenen Ausgaben löschen.', 403);
        }
        db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([$e['id']]);
        out(trip_payload((int)$e['trip_id']));

    default:
        fail('Unbekannte Aktion.', 404);
}
