<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    start_secure_session();
    $pdo = database_connection();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $user = current_user($pdo);
        respond(200, [
            'authenticated' => $user !== null,
            'user' => $user,
            'csrf' => csrf_token(),
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Deze HTTP-methode is niet toegestaan.']);
    }

    $body = read_json_body(16384);
    require_csrf();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'logout') {
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Could not rotate the session ID.');
        }
        unset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_name']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(200, ['authenticated' => false, 'user' => null, 'csrf' => $_SESSION['csrf_token']]);
    }

    if ($action !== 'login' && $action !== 'register') {
        respond(400, ['error' => 'Onbekende accountactie.']);
    }

    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        respond(400, ['error' => 'Vul een geldig e-mailadres in.']);
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        respond(400, ['error' => 'Gebruik een wachtwoord van 8 tot en met 72 bytes.']);
    }

    if ($action === 'register') {
        $displayName = trim((string) ($body['name'] ?? ''));
        if (!preg_match('/^.{1,80}$/us', $displayName)) {
            respond(400, ['error' => 'Vul een naam van maximaal 80 tekens in.']);
        }

        $statement = $pdo->prepare('INSERT INTO users (email, display_name, password_hash) VALUES (?, ?, ?)');
        try {
            $statement->execute([$email, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                respond(409, ['error' => 'Er bestaat al een account met dit e-mailadres.']);
            }
            throw $error;
        }

        $user = [
            'id' => (int) $pdo->lastInsertId(),
            'email' => $email,
            'name' => $displayName,
            'isAdmin' => false,
        ];
    } else {
        $emailHash = hash('sha256', $email);
        $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $attempts = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND (email_hash = ? OR ip_hash = ?)'
        );
        $attempts->execute([$emailHash, $ipHash]);
        if ((int) $attempts->fetchColumn() >= 10) {
            respond(429, ['error' => 'Te veel inlogpogingen. Wacht 15 minuten en probeer opnieuw.']);
        }

        $statement = $pdo->prepare('SELECT id, email, display_name, password_hash, is_admin FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $record = $statement->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) {
            $recordFailure = $pdo->prepare('INSERT INTO login_attempts (email_hash, ip_hash) VALUES (?, ?)');
            $recordFailure->execute([$emailHash, $ipHash]);
            respond(401, ['error' => 'E-mailadres of wachtwoord is onjuist.']);
        }

        if (password_needs_rehash($record['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $record['id']]);
        }
        $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ?');
        $clearAttempts->execute([$emailHash]);
        $user = [
            'id' => (int) $record['id'],
            'email' => $record['email'],
            'name' => $record['display_name'],
            'isAdmin' => (bool) $record['is_admin'],
        ];
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Could not rotate the session ID.');
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    respond(200, ['authenticated' => true, 'user' => $user, 'csrf' => $_SESSION['csrf_token']]);
} catch (Throwable $error) {
    log_api_failure($error);
    respond(503, ['error' => 'De accountserver is tijdelijk niet beschikbaar. Controleer de serverconfiguratie.']);
}
