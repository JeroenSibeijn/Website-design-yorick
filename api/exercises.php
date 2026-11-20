<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const SHARED_CATALOG_MIGRATION = 'legacy_catalogs_v1';
const BUILT_IN_CATEGORIES = ['plezier', 'houding', 'techniek', 'snelheid'];

function shared_category_id($value): string
{
    $id = (string) $value;
    if (!preg_match('/^custom-category-[a-z0-9-]{1,100}$/', $id)) {
        throw new InvalidArgumentException('De categorie heeft een ongeldig ID.');
    }
    return $id;
}

function shared_exercise_id($value): string
{
    $id = (string) $value;
    if (!preg_match('/^custom-exercise-[a-z0-9-]{1,100}$/', $id)) {
        throw new InvalidArgumentException('De oefening heeft een ongeldig ID.');
    }
    return $id;
}

function shared_item_deleted(PDO $pdo, string $type, string $id): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM shared_exercise_tombstones WHERE item_type = ? AND id = ?'
    );
    $statement->execute([$type, $id]);
    return (bool) $statement->fetchColumn();
}

function legacy_created_at(string $id, string $type): string
{
    $prefix = $type === 'category' ? 'custom-category-' : 'custom-exercise-';
    $suffix = substr($id, strlen($prefix));
    if (preg_match('/^([a-z0-9]{7,9})[a-z0-9]{6}$/', $suffix, $matches)) {
        $milliseconds = (int) base_convert($matches[1], 36, 10);
        if ($milliseconds >= 946684800000 && $milliseconds <= (time() + 86400) * 1000) {
            return gmdate('Y-m-d H:i:s', (int) floor($milliseconds / 1000));
        }
    }
    return gmdate('Y-m-d H:i:s');
}

function shared_category_values($category): array
{
    if (!is_array($category)) {
        throw new InvalidArgumentException('De categoriegegevens zijn ongeldig.');
    }
    $id = shared_category_id($category['id'] ?? '');
    $name = trim((string) ($category['name'] ?? ''));
    $tone = (string) ($category['tone'] ?? '');
    if ($name === '' || strlen($name) > 50 || !in_array($tone, ['blue', 'orange'], true)) {
        throw new InvalidArgumentException('Vul een categorienaam van maximaal 50 tekens in en kies een geldige kleur.');
    }
    return ['id' => $id, 'name' => $name, 'tone' => $tone];
}

function shared_url($value): string
{
    $url = trim((string) $value);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false ||
        !is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
        empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Video- en animatielinks moeten een geldige https-link zijn.');
    }
    return $url;
}

function shared_media(PDO $pdo, $value, int $userId, string $purpose, bool $allowShared): array
{
    $id = (string) $value;
    if ($id === '') {
        return ['id' => null, 'mimeType' => ''];
    }
    if (!preg_match('/^[a-f0-9]{32}$/i', $id)) {
        throw new InvalidArgumentException('Een mediabestand heeft een ongeldig ID.');
    }

    $statement = $pdo->prepare(
        'SELECT user_id, purpose, mime_type, is_shared, file_size
           FROM user_media
          WHERE id = ? AND (user_id = ? OR is_shared = 1)'
    );
    $statement->execute([strtolower($id), $userId]);
    $media = $statement->fetch();
    $videoTypes = ['video/mp4', 'video/webm'];
    $animationTypes = array_merge($videoTypes, ['image/gif', 'image/webp', 'image/png', 'image/jpeg']);
    $allowedTypes = $purpose === 'video' ? $videoTypes : $animationTypes;
    if (!$media || (string) $media['purpose'] !== $purpose ||
        !in_array((string) $media['mime_type'], $allowedTypes, true) ||
        ((string) $media['mime_type'] === 'image/gif' && (int) $media['file_size'] > 104857600) ||
        ((int) $media['user_id'] !== $userId && (!$allowShared || !(bool) $media['is_shared']))) {
        throw new InvalidArgumentException('Het geselecteerde mediabestand is niet beschikbaar voor deze oefening.');
    }

    return ['id' => strtolower($id), 'mimeType' => (string) $media['mime_type']];
}

function publish_shared_media(PDO $pdo, array $mediaIds): void
{
    $statement = $pdo->prepare('UPDATE user_media SET is_shared = 1 WHERE id = ?');
    foreach (array_filter($mediaIds) as $mediaId) {
        $statement->execute([$mediaId]);
    }
}

function shared_exercise_values(
    PDO $pdo,
    $exercise,
    int $userId,
    bool $allowSharedMedia
): array {
    if (!is_array($exercise)) {
        throw new InvalidArgumentException('De oefeninggegevens zijn ongeldig.');
    }

    $id = shared_exercise_id($exercise['id'] ?? '');
    $categoryId = trim((string) ($exercise['categoryId'] ?? ''));
    if (!in_array($categoryId, BUILT_IN_CATEGORIES, true)) {
        $categoryId = shared_category_id($categoryId);
        $category = $pdo->prepare('SELECT id FROM shared_exercise_categories WHERE id = ?');
        $category->execute([$categoryId]);
        if (!$category->fetchColumn()) {
            throw new InvalidArgumentException('De gekozen categorie bestaat niet.');
        }
    }

    $name = trim((string) ($exercise['name'] ?? ''));
    $explanation = trim((string) ($exercise['explanation'] ?? ''));
    $minutes = filter_var($exercise['minutes'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '' || strlen($name) > 100 || $explanation === '' || strlen($explanation) > 2000 ||
        $minutes === false || $minutes < 1 || $minutes > 240) {
        throw new InvalidArgumentException('Vul de naam en verplichte uitleg in en controleer de duur van de oefening.');
    }

    $videoUrl = shared_url($exercise['videoUrl'] ?? '');
    $animationUrl = shared_url($exercise['animationUrl'] ?? '');
    if ($videoUrl !== '' && !empty($exercise['videoMediaId'])) {
        throw new InvalidArgumentException('Kies voor de uitlegvideo een URL of een upload, niet allebei.');
    }
    if ($animationUrl !== '' && !empty($exercise['animationMediaId'])) {
        throw new InvalidArgumentException('Kies voor de animatie een URL of een upload, niet allebei.');
    }
    $video = shared_media($pdo, $exercise['videoMediaId'] ?? '', $userId, 'video', $allowSharedMedia);
    $animation = shared_media($pdo, $exercise['animationMediaId'] ?? '', $userId, 'animation', $allowSharedMedia);

    return [
        'id' => $id,
        'categoryId' => $categoryId,
        'name' => $name,
        'explanation' => $explanation,
        'minutes' => (int) $minutes,
        'videoUrl' => $videoUrl,
        'videoMediaId' => $video['id'],
        'animationUrl' => $animationUrl,
        'animationMediaId' => $animation['id'],
    ];
}

function import_legacy_catalog(
    PDO $pdo,
    int $userId,
    $catalog,
    bool $allowSharedMedia = false,
    bool $allowCategoryCreation = true
): void
{
    if (!is_array($catalog) || ($catalog['version'] ?? null) !== 1) {
        return;
    }
    $categories = is_array($catalog['categories'] ?? null) ? $catalog['categories'] : [];
    $exercises = is_array($catalog['exercises'] ?? null) ? $catalog['exercises'] : [];

    foreach ($categories as $category) {
        if (!$allowCategoryCreation) {
            error_log('Schaatskompas ignored a shared category import from non-admin user ' . $userId . '.');
            continue;
        }
        try {
            $values = shared_category_values($category);
            if (shared_item_deleted($pdo, 'category', $values['id'])) {
                continue;
            }
            $statement = $pdo->prepare(
                'INSERT IGNORE INTO shared_exercise_categories (id, name, tone, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $values['id'], $values['name'], $values['tone'], $userId,
                legacy_created_at($values['id'], 'category'),
            ]);
        } catch (InvalidArgumentException $error) {
            error_log('Schaatskompas skipped an invalid legacy exercise category for user ' . $userId . '.');
        }
    }

    foreach ($exercises as $exercise) {
        try {
            $values = shared_exercise_values($pdo, $exercise, $userId, $allowSharedMedia);
            if (shared_item_deleted($pdo, 'exercise', $values['id'])) {
                continue;
            }
            $statement = $pdo->prepare(
                'INSERT IGNORE INTO shared_exercises
                    (id, category_id, name, explanation, minutes, video_url, video_media_id,
                     animation_url, animation_media_id, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $values['id'], $values['categoryId'], $values['name'], $values['explanation'],
                $values['minutes'], $values['videoUrl'], $values['videoMediaId'],
                $values['animationUrl'], $values['animationMediaId'], $userId,
                legacy_created_at($values['id'], 'exercise'),
            ]);
            if ($statement->rowCount() > 0) {
                publish_shared_media($pdo, [$values['videoMediaId'], $values['animationMediaId']]);
            }
        } catch (InvalidArgumentException $error) {
            error_log('Schaatskompas skipped an invalid legacy exercise for user ' . $userId . '.');
        }
    }
}

function annotate_catalog_owners(PDO $pdo, array $catalog): array
{
    $changed = false;
    foreach (['categories', 'exercises'] as $type) {
        $rows = $catalog[$type] ?? null;
        if (!is_array($rows)) {
            continue;
        }
        $table = $type === 'categories' ? 'shared_exercise_categories' : 'shared_exercises';
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $idPattern = $type === 'categories' ?
                '/^custom-category-[a-z0-9-]{1,100}$/' :
                '/^custom-exercise-[a-z0-9-]{1,100}$/';
            if (!preg_match($idPattern, (string) $row['id'])) {
                continue;
            }
            $statement = $pdo->prepare('SELECT created_by FROM ' . $table . ' WHERE id = ?');
            $statement->execute([(string) $row['id']]);
            $ownerId = $statement->fetchColumn();
            if ($ownerId === false) {
                if (array_key_exists('createdBy', $row)) {
                    unset($catalog[$type][$index]['createdBy']);
                    $changed = true;
                }
                continue;
            }
            if (!isset($row['createdBy']) || (int) $row['createdBy'] !== (int) $ownerId) {
                $catalog[$type][$index]['createdBy'] = (int) $ownerId;
                $changed = true;
            }
        }
    }
    return [$catalog, $changed];
}

function migrate_legacy_catalogs(PDO $pdo): void
{
    $check = $pdo->prepare('SELECT migration_key FROM shared_exercise_migrations WHERE migration_key = ?');
    $check->execute([SHARED_CATALOG_MIGRATION]);
    if ($check->fetchColumn()) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $check->execute([SHARED_CATALOG_MIGRATION]);
        if (!$check->fetchColumn()) {
            $records = $pdo->query(
                'SELECT app_data.user_id, app_data.data_json, users.is_admin
                   FROM user_app_data AS app_data
                   JOIN users ON users.id = app_data.user_id
                  ORDER BY users.is_admin DESC, app_data.user_id'
            );
            while ($record = $records->fetch()) {
                $snapshot = json_decode((string) $record['data_json'], true);
                $entries = is_array($snapshot) && is_array($snapshot['entries'] ?? null) ?
                    $snapshot['entries'] : [];
                $catalogJson = $entries['schaatskompas-exercise-catalog-v1'] ?? null;
                if (!is_string($catalogJson)) {
                    continue;
                }
                $catalog = json_decode($catalogJson, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    import_legacy_catalog(
                        $pdo,
                        (int) $record['user_id'],
                        $catalog,
                        false,
                        (bool) $record['is_admin']
                    );
                    if (is_array($catalog) && ($catalog['version'] ?? null) === 1) {
                        list($catalog, $changed) = annotate_catalog_owners($pdo, $catalog);
                        if ($changed) {
                            $snapshot['entries']['schaatskompas-exercise-catalog-v1'] =
                                json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if ($snapshotJson === false ||
                                strlen($snapshotJson) > 3145728 ||
                                $snapshot['entries']['schaatskompas-exercise-catalog-v1'] === false) {
                                error_log('Schaatskompas could not annotate a legacy exercise catalog for user ' . (int) $record['user_id'] . '.');
                                continue;
                            }
                            $update = $pdo->prepare(
                                'UPDATE user_app_data
                                    SET data_json = ?, revision = revision + 1, updated_at = UTC_TIMESTAMP(6)
                                  WHERE user_id = ?'
                            );
                            $update->execute([$snapshotJson, $record['user_id']]);
                        }
                    }
                } else {
                    error_log('Schaatskompas skipped an unreadable legacy exercise catalog for user ' . (int) $record['user_id'] . '.');
                }
            }
            $insert = $pdo->prepare('INSERT IGNORE INTO shared_exercise_migrations (migration_key) VALUES (?)');
            $insert->execute([SHARED_CATALOG_MIGRATION]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function shared_catalog(PDO $pdo): array
{
    $categories = $pdo->query(
        'SELECT id, name, tone, created_by, created_at FROM shared_exercise_categories ORDER BY name, created_at'
    )->fetchAll();
    $exercises = $pdo->query(
        'SELECT e.id, e.category_id, e.name, e.explanation, e.minutes, e.video_url, e.video_media_id, e.created_at,
                video.mime_type AS video_mime_type, e.animation_url, e.animation_media_id,
                animation.mime_type AS animation_mime_type, e.created_by
           FROM shared_exercises e
           LEFT JOIN user_media video ON video.id = e.video_media_id
           LEFT JOIN user_media animation ON animation.id = e.animation_media_id
          ORDER BY e.created_at, e.name'
    )->fetchAll();

    return [
        'version' => 1,
        'categories' => array_map(function ($category) {
            return [
                'id' => (string) $category['id'],
                'name' => (string) $category['name'],
                'tone' => (string) $category['tone'],
                'createdBy' => (int) $category['created_by'],
                'createdAt' => (string) $category['created_at'],
            ];
        }, $categories),
        'exercises' => array_map(function ($exercise) {
            return [
                'id' => (string) $exercise['id'],
                'categoryId' => (string) $exercise['category_id'],
                'name' => (string) $exercise['name'],
                'explanation' => (string) $exercise['explanation'],
                'minutes' => (int) $exercise['minutes'],
                'videoUrl' => (string) $exercise['video_url'],
                'videoMediaId' => (string) ($exercise['video_media_id'] ?? ''),
                'videoMimeType' => (string) ($exercise['video_mime_type'] ?? ''),
                'animationUrl' => (string) $exercise['animation_url'],
                'animationMediaId' => (string) ($exercise['animation_media_id'] ?? ''),
                'animationMimeType' => (string) ($exercise['animation_mime_type'] ?? ''),
                'createdBy' => (int) $exercise['created_by'],
                'createdAt' => (string) $exercise['created_at'],
            ];
        }, $exercises),
    ];
}

function can_manage_shared_item(PDO $pdo, string $table, string $id, array $user): bool
{
    if (!in_array($table, ['shared_exercises', 'shared_exercise_categories'], true)) {
        throw new RuntimeException('Invalid shared catalog table.');
    }
    if (!empty($user['isAdmin'])) {
        return true;
    }
    $statement = $pdo->prepare('SELECT created_by FROM ' . $table . ' WHERE id = ?');
    $statement->execute([$id]);
    return (int) $statement->fetchColumn() === (int) $user['id'];
}

function remove_orphaned_shared_media(PDO $pdo, array $mediaIds): void
{
    foreach (array_filter($mediaIds) as $mediaId) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM shared_exercises WHERE video_media_id = ? OR animation_media_id = ?'
        );
        $statement->execute([$mediaId, $mediaId]);
        if ((int) $statement->fetchColumn() === 0) {
            $unshare = $pdo->prepare('UPDATE user_media SET is_shared = 0 WHERE id = ?');
            $unshare->execute([$mediaId]);
        }
    }
}

try {
    start_secure_session();
    $pdo = database_connection();
    $user = require_user($pdo);
    migrate_legacy_catalogs($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Deze HTTP-methode is niet toegestaan.']);
    }

    require_csrf();
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'import') {
        $catalog = $body['catalog'] ?? null;
        if (!is_array($catalog) || ($catalog['version'] ?? null) !== 1 ||
            !is_array($catalog['categories'] ?? null) || !is_array($catalog['exercises'] ?? null)) {
            throw new InvalidArgumentException('De oefeningencatalogus heeft een ongeldig formaat.');
        }
        $pdo->beginTransaction();
        import_legacy_catalog($pdo, (int) $user['id'], $catalog, true, !empty($user['isAdmin']));
        $pdo->commit();
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'create_category') {
        if (empty($user['isAdmin'])) {
            respond(403, ['error' => 'Alleen beheerders kunnen nieuwe categorieën aanmaken.']);
        }
        $category = shared_category_values($body['category'] ?? null);
        if (shared_item_deleted($pdo, 'category', $category['id'])) {
            respond(409, ['error' => 'Deze categorie is verwijderd. Maak een nieuwe categorie aan.']);
        }
        $statement = $pdo->prepare(
            'INSERT INTO shared_exercise_categories (id, name, tone, created_by) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$category['id'], $category['name'], $category['tone'], $user['id']]);
        respond(201, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'create_exercise') {
        $requestedExercise = $body['exercise'] ?? null;
        if (!is_array($requestedExercise)) {
            throw new InvalidArgumentException('De oefeninggegevens zijn ongeldig.');
        }
        $requestedId = shared_exercise_id($requestedExercise['id'] ?? '');
        if (shared_item_deleted($pdo, 'exercise', $requestedId)) {
            respond(409, ['error' => 'Deze oefening is verwijderd. Maak een nieuwe oefening aan.']);
        }
        $pdo->beginTransaction();
        $exercise = shared_exercise_values($pdo, $requestedExercise, (int) $user['id'], true);
        $statement = $pdo->prepare(
            'INSERT INTO shared_exercises
                (id, category_id, name, explanation, minutes, video_url, video_media_id,
                 animation_url, animation_media_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $exercise['id'], $exercise['categoryId'], $exercise['name'], $exercise['explanation'],
            $exercise['minutes'], $exercise['videoUrl'], $exercise['videoMediaId'],
            $exercise['animationUrl'], $exercise['animationMediaId'], $user['id'],
        ]);
        publish_shared_media($pdo, [$exercise['videoMediaId'], $exercise['animationMediaId']]);
        $pdo->commit();
        respond(201, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'update_exercise') {
        $requestedExercise = $body['exercise'] ?? null;
        if (!is_array($requestedExercise)) {
            throw new InvalidArgumentException('De oefeninggegevens zijn ongeldig.');
        }
        $id = shared_exercise_id($requestedExercise['id'] ?? '');
        if (!can_manage_shared_item($pdo, 'shared_exercises', $id, $user)) {
            respond(403, ['error' => 'Alleen de maker of een beheerder kan deze oefening aanpassen.']);
        }
        $pdo->beginTransaction();
        $exercise = shared_exercise_values($pdo, $requestedExercise, (int) $user['id'], true);
        $oldMedia = $pdo->prepare('SELECT video_media_id, animation_media_id FROM shared_exercises WHERE id = ?');
        $oldMedia->execute([$exercise['id']]);
        $oldMediaIds = $oldMedia->fetch();
        $statement = $pdo->prepare(
            'UPDATE shared_exercises
                SET category_id = ?, name = ?, explanation = ?, minutes = ?, video_url = ?,
                    video_media_id = ?, animation_url = ?, animation_media_id = ?
              WHERE id = ?'
        );
        $statement->execute([
            $exercise['categoryId'], $exercise['name'], $exercise['explanation'], $exercise['minutes'],
            $exercise['videoUrl'], $exercise['videoMediaId'], $exercise['animationUrl'],
            $exercise['animationMediaId'], $exercise['id'],
        ]);
        publish_shared_media($pdo, [$exercise['videoMediaId'], $exercise['animationMediaId']]);
        if ($oldMediaIds) {
            remove_orphaned_shared_media($pdo, [$oldMediaIds['video_media_id'], $oldMediaIds['animation_media_id']]);
        }
        $pdo->commit();
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'delete_exercise') {
        $id = shared_exercise_id($body['id'] ?? '');
        if (!can_manage_shared_item($pdo, 'shared_exercises', $id, $user)) {
            respond(403, ['error' => 'Alleen de maker of een beheerder kan deze oefening verwijderen.']);
        }
        $pdo->beginTransaction();
        $tombstone = $pdo->prepare(
            'INSERT INTO shared_exercise_tombstones (item_type, id, deleted_by) VALUES (?, ?, ?)'
        );
        $tombstone->execute(['exercise', $id, $user['id']]);
        $media = $pdo->prepare('SELECT video_media_id, animation_media_id FROM shared_exercises WHERE id = ?');
        $media->execute([$id]);
        $mediaIds = $media->fetch();
        $delete = $pdo->prepare('DELETE FROM shared_exercises WHERE id = ?');
        $delete->execute([$id]);
        if ($mediaIds) {
            remove_orphaned_shared_media($pdo, [$mediaIds['video_media_id'], $mediaIds['animation_media_id']]);
        }
        $pdo->commit();
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'update_category') {
        $category = shared_category_values($body['category'] ?? null);
        if (!can_manage_shared_item($pdo, 'shared_exercise_categories', $category['id'], $user)) {
            respond(403, ['error' => 'Alleen de maker of een beheerder kan deze categorie aanpassen.']);
        }
        $statement = $pdo->prepare('UPDATE shared_exercise_categories SET name = ?, tone = ? WHERE id = ?');
        $statement->execute([$category['name'], $category['tone'], $category['id']]);
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    if ($action === 'delete_category') {
        $id = shared_category_id($body['id'] ?? '');
        if (!can_manage_shared_item($pdo, 'shared_exercise_categories', $id, $user)) {
            respond(403, ['error' => 'Alleen de maker of een beheerder kan deze categorie verwijderen.']);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM shared_exercises WHERE category_id = ?');
        $count->execute([$id]);
        if ((int) $count->fetchColumn() !== 0) {
            throw new InvalidArgumentException('Verwijder eerst de oefeningen uit deze categorie.');
        }
        $pdo->beginTransaction();
        $tombstone = $pdo->prepare(
            'INSERT INTO shared_exercise_tombstones (item_type, id, deleted_by) VALUES (?, ?, ?)'
        );
        $tombstone->execute(['category', $id, $user['id']]);
        $statement = $pdo->prepare('DELETE FROM shared_exercise_categories WHERE id = ?');
        $statement->execute([$id]);
        $pdo->commit();
        respond(200, ['catalog' => shared_catalog($pdo)]);
    }

    respond(400, ['error' => 'Deze catalogusactie is niet bekend.']);
} catch (InvalidArgumentException $error) {
    respond(400, ['error' => $error->getMessage()]);
} catch (PDOException $error) {
    if (($error->errorInfo[1] ?? null) === 1062) {
        respond(409, ['error' => 'Deze categorie of oefening bestaat al. Vernieuw de pagina en probeer opnieuw.']);
    }
    log_api_failure($error);
    respond(503, ['error' => 'De gedeelde oefeningencatalogus is tijdelijk niet beschikbaar.']);
} catch (Throwable $error) {
    log_api_failure($error);
    respond(503, ['error' => 'De gedeelde oefeningencatalogus is tijdelijk niet beschikbaar.']);
}
