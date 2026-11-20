<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log('Schaatskompas API could not encode a JSON response.');
        echo '{"error":"Serverfout."}';
        exit;
    }
    echo $json;
    exit;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_name('schaatskompas_session');
    $sitePath = dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api/auth.php')));
    $cookiePath = rtrim(str_replace('\\', '/', $sitePath), '/') . '/';
    if ($cookiePath === '//') {
        $cookiePath = '/';
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Could not start the session.');
    }
}

function database_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('SCHAAKSKOMPAS_DB_HOST');
    $name = getenv('SCHAAKSKOMPAS_DB_NAME');
    $user = getenv('SCHAAKSKOMPAS_DB_USER');
    $password = getenv('SCHAAKSKOMPAS_DB_PASSWORD');
    $port = getenv('SCHAAKSKOMPAS_DB_PORT') ?: '3306';

    if ($host === false || $name === false || $user === false || $password === false ||
        !preg_match('/^[A-Za-z0-9_]+$/', $name) || !preg_match('/^\d+$/', $port)) {
        throw new RuntimeException('Database environment variables are missing or invalid.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function read_json_body(int $maxBytes = 3145728): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== 0) {
        respond(415, ['error' => 'Verwacht een JSON-verzoek.']);
    }
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        respond(413, ['error' => 'Het verzoek is te groot.']);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > $maxBytes) {
        respond(413, ['error' => 'Het verzoek is te groot of kon niet worden gelezen.']);
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        respond(400, ['error' => 'Het JSON-verzoek is ongeldig.']);
    }
    return $data;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        respond(403, ['error' => 'Beveiligingscontrole mislukt. Vernieuw de pagina en probeer opnieuw.']);
    }
}

function current_user(PDO $pdo): ?array
{
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        return null;
    }

    $statement = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = ?');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if (!$user) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'name' => $user['display_name'],
    ];
}

function require_user(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        respond(401, ['error' => 'Log in om deze gegevens te gebruiken.']);
    }
    return $user;
}

function log_api_failure(Throwable $error): void
{
    error_log('Schaatskompas API error: ' . $error->getMessage());
}
