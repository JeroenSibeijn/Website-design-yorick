<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const MAX_MEDIA_BYTES = 524288000;
const MAX_GIF_BYTES = 104857600;

function media_extensions(): array
{
    return [
        'video' => [
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
        'animation' => [
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ],
    ];
}

function media_storage_directory(): string
{
    $configured = getenv('SCHAAKSKOMPAS_UPLOAD_DIR');
    if ($configured === false || $configured === '') {
        throw new RuntimeException('SCHAAKSKOMPAS_UPLOAD_DIR is not configured.');
    }

    $storage = realpath($configured);
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($storage === false || $documentRoot === false || !is_dir($storage) || !is_writable($storage)) {
        throw new RuntimeException('The media storage directory is unavailable or not writable.');
    }

    $storagePrefix = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $documentPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $comparison = DIRECTORY_SEPARATOR === '\\' ? strncasecmp($storagePrefix, $documentPrefix, strlen($documentPrefix)) :
        strncmp($storagePrefix, $documentPrefix, strlen($documentPrefix));
    if ($comparison === 0 || $storage === $documentRoot) {
        throw new RuntimeException('Media storage must be outside the public document root.');
    }

    return $storage;
}

function media_file_path(string $storage, int $userId, string $mediaId, string $extension): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $mediaId) || !preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
        throw new RuntimeException('Media metadata contains an invalid storage key.');
    }

    return $storage . DIRECTORY_SEPARATOR . $userId . DIRECTORY_SEPARATOR . $mediaId . '.' . $extension;
}

function stream_media_file(string $path, string $mimeType, int $size, string $extension): void
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        respond(503, ['error' => 'Het mediabestand kan niet worden geopend.']);
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || $size === 0 ||
            ($matches[1] === '' && $matches[2] === '')) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            respond(416, ['error' => 'Het aangevraagde mediabereik is ongeldig.']);
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength < 1) {
                fclose($handle);
                header('Content-Range: bytes */' . $size);
                respond(416, ['error' => 'Het aangevraagde mediabereik is ongeldig.']);
            }
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
        }

        if ($start >= $size || $start > $end) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            respond(416, ['error' => 'Het aangevraagde mediabereik valt buiten het bestand.']);
        }
        $end = min($end, $size - 1);
        $status = 206;
    }

    if (fseek($handle, $start) !== 0) {
        fclose($handle);
        respond(503, ['error' => 'Het mediabestand kan niet worden gelezen.']);
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="media.' . $extension . '"');
    header('Cache-Control: private, no-store');
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    while ($length > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1048576, $length));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $length -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

try {
    start_secure_session();
    $pdo = database_connection();
    $user = require_user($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $id = strtolower(trim((string) ($_GET['id'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            respond(400, ['error' => 'Het media-ID is ongeldig.']);
        }

        $statement = $pdo->prepare(
            'SELECT user_id, mime_type, file_extension, file_size
               FROM user_media
              WHERE id = ? AND (user_id = ? OR is_shared = 1)'
        );
        $statement->execute([$id, $user['id']]);
        $media = $statement->fetch();
        if (!$media) {
            respond(404, ['error' => 'Dit mediabestand is niet gevonden.']);
        }

        $mimeType = (string) $media['mime_type'];
        $extension = (string) $media['file_extension'];
        $allowed = media_extensions();
        $validExtensions = array_merge($allowed['video'], $allowed['animation']);
        if (!isset($validExtensions[$mimeType]) || $validExtensions[$mimeType] !== $extension) {
            throw new RuntimeException('Media metadata has an unsupported content type.');
        }

        $storage = media_storage_directory();
        $path = media_file_path($storage, (int) $media['user_id'], $id, $extension);
        if (!is_file($path) || filesize($path) !== (int) $media['file_size']) {
            throw new RuntimeException('The stored media file is missing or has changed.');
        }

        stream_media_file($path, $mimeType, (int) $media['file_size'], $extension);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Deze HTTP-methode is niet toegestaan.']);
    }

    require_csrf();
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_FILES) && $contentLength > 0) {
        respond(413, ['error' => 'Upload geweigerd. Verhoog PHP post_max_size tot minimaal 510M.']);
    }

    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
        respond(400, ['error' => 'Selecteer een videobestand of animatiebestand.']);
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $message = (int) $upload['error'] === UPLOAD_ERR_INI_SIZE || (int) $upload['error'] === UPLOAD_ERR_FORM_SIZE ?
            'Het bestand overschrijdt de uploadlimiet van deze PHP-server.' :
            'Het bestand kon niet volledig worden geüpload. Controleer de tijdelijke opslag van de server.';
        respond(400, ['error' => $message]);
    }

    $size = (int) $upload['size'];
    if ($size < 1 || $size > MAX_MEDIA_BYTES || !is_uploaded_file((string) $upload['tmp_name'])) {
        respond(413, ['error' => 'Een video of animatie moet groter zijn dan 0 bytes en maximaal 500 MB.']);
    }

    $purpose = (string) ($_POST['purpose'] ?? '');
    $allowed = media_extensions();
    if (!isset($allowed[$purpose])) {
        respond(400, ['error' => 'Kies video of animatie als bestandstype.']);
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file((string) $upload['tmp_name']);
    if (!is_string($mimeType) || !isset($allowed[$purpose][$mimeType])) {
        respond(415, ['error' => $purpose === 'video' ?
            'Gebruik een MP4- of WebM-videobestand.' :
            'Gebruik een GIF-, WebP-, PNG-, JPG-, MP4- of WebM-bestand voor de animatie.']);
    }
    if ($mimeType === 'image/gif' && $size > MAX_GIF_BYTES) {
        respond(413, ['error' => 'Een GIF mag maximaal 100 MB zijn.']);
    }

    $storage = media_storage_directory();
    $userDirectory = $storage . DIRECTORY_SEPARATOR . (int) $user['id'];
    if (!is_dir($userDirectory) && !mkdir($userDirectory, 0700, true) && !is_dir($userDirectory)) {
        throw new RuntimeException('Could not create the user media directory.');
    }
    $realUserDirectory = realpath($userDirectory);
    if ($realUserDirectory === false ||
        strpos($realUserDirectory . DIRECTORY_SEPARATOR, rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('The user media directory is outside the configured storage directory.');
    }

    $id = bin2hex(random_bytes(16));
    $extension = $allowed[$purpose][$mimeType];
    $destination = media_file_path($storage, (int) $user['id'], $id, $extension);
    if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
        throw new RuntimeException('Could not move the uploaded media file.');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO user_media (id, user_id, purpose, mime_type, file_extension, file_size)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, $user['id'], $purpose, $mimeType, $extension, $size]);
    } catch (Throwable $error) {
        if (is_file($destination) && !unlink($destination)) {
            error_log('Schaatskompas could not remove an unregistered media upload.');
        }
        throw $error;
    }

    respond(201, ['media' => ['id' => $id, 'purpose' => $purpose, 'mimeType' => $mimeType, 'size' => $size]]);
} catch (Throwable $error) {
    log_api_failure($error);
    respond(503, ['error' => 'De mediaserver is tijdelijk niet beschikbaar. Controleer de serverconfiguratie.']);
}
