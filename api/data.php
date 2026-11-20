<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function validate_app_data($data): string
{
    if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
        !isset($data['entries']) || !is_array($data['entries'])) {
        respond(400, ['error' => 'De opgeslagen gegevens hebben een ongeldig formaat.']);
    }

    $allowedKeys = ['schaatskompas-groepen-v1', 'schaatskompas-exercise-catalog-v1'];
    foreach ($data['entries'] as $key => $value) {
        if (!in_array($key, $allowedKeys, true) || !is_string($value)) {
            respond(400, ['error' => 'De gegevens bevatten een onbekende opslagvermelding.']);
        }
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            respond(400, ['error' => 'Een opgeslagen gegevensvermelding bevat ongeldige JSON.']);
        }
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 3145728) {
        respond(413, ['error' => 'De gegevens zijn te groot om op te slaan.']);
    }
    return $json;
}

try {
    start_secure_session();
    $pdo = database_connection();
    $user = require_user($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $statement = $pdo->prepare('SELECT data_json, revision, updated_at FROM user_app_data WHERE user_id = ?');
        $statement->execute([$user['id']]);
        $record = $statement->fetch();
        if (!$record) {
            respond(200, ['data' => null, 'revision' => null, 'updatedAt' => null]);
        }

        $data = json_decode($record['data_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('Stored user data is not valid JSON.');
        }
        respond(200, [
            'data' => $data,
            'revision' => (int) $record['revision'],
            'updatedAt' => $record['updated_at'],
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Deze HTTP-methode is niet toegestaan.']);
    }

    require_csrf();
    $body = read_json_body();
    $json = validate_app_data($body['data'] ?? null);
    $revision = $body['revision'] ?? null;

    if ($revision === null) {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO user_app_data (user_id, data_json, revision) VALUES (?, ?, 1)'
            );
            $statement->execute([$user['id'], $json]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                $current = $pdo->prepare('SELECT revision FROM user_app_data WHERE user_id = ?');
                $current->execute([$user['id']]);
                respond(409, ['error' => 'Je accountgegevens zijn intussen op een ander apparaat gewijzigd.', 'revision' => (int) $current->fetchColumn()]);
            }
            throw $error;
        }
        respond(200, ['revision' => 1]);
    }

    if (!is_int($revision) || $revision < 1) {
        respond(400, ['error' => 'De synchronisatieversie is ongeldig.']);
    }

    $statement = $pdo->prepare(
        'UPDATE user_app_data
            SET data_json = ?, revision = revision + 1, updated_at = UTC_TIMESTAMP(6)
          WHERE user_id = ? AND revision = ?'
    );
    $statement->execute([$json, $user['id'], $revision]);
    if ($statement->rowCount() !== 1) {
        $current = $pdo->prepare('SELECT revision FROM user_app_data WHERE user_id = ?');
        $current->execute([$user['id']]);
        $currentRevision = $current->fetchColumn();
        respond(409, [
            'error' => 'Je accountgegevens zijn intussen op een ander apparaat gewijzigd.',
            'revision' => $currentRevision === false ? null : (int) $currentRevision,
        ]);
    }

    respond(200, ['revision' => $revision + 1]);
} catch (Throwable $error) {
    log_api_failure($error);
    respond(503, ['error' => 'De gegevensserver is tijdelijk niet beschikbaar. Je lokale gegevens blijven behouden.']);
}
