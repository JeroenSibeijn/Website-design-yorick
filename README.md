# Schaatskompas

Schaatskompas is a Dutch-language skating coaching and training planner built with HTML, CSS, JavaScript, PHP, and MySQL/MariaDB.

## Run the website

There is no JavaScript build step or package installation. Open `Main.dc.html` for the local browser-only mode, or configure Apache to serve the repository root for PHP accounts and server synchronization. Keep the repository root as the document root; exercise pages load shared files and the API through relative paths.

## Main pages

- `Main.dc.html` - season overview and upcoming trainings.
- `Groepen.dc.html` - groups, children, progress, and training records.
- `Builder.dc.html` - build and save a training for a group.
- `oefeningen/Oefeningen.dc.html` - exercise catalogue index.
- `oefeningen/` - exercise categories, individual explanations and animations, and exercise detail pages.
- `OpHetIjs.dc.html` - guided training mode, per-part notes, and fullscreen animations.
- `Inloggen.dc.html` - account registration, login, sync, backup download, and sign-out.

## Configure the self-hosted server

1. Use Apache with PHP 7.4+ and the `pdo_mysql` and `fileinfo` extensions enabled.
2. Create a MySQL/MariaDB database and an application database user with access only to that database. Run `database/schema.sql`.
   For an existing installation, run `database/migrate-shared-exercises.sql` once before serving the updated site. Existing account exercise catalogs are imported into the shared catalog on its first authenticated request.
3. Create a writable media directory outside Apache's public document root, for example `/var/lib/schaatskompas/media`, and configure `SCHAAKSKOMPAS_UPLOAD_DIR` to that absolute path. Apache/PHP must be able to write to it. Back up this directory along with the database.
4. Configure Apache's PHP environment with `SCHAAKSKOMPAS_DB_HOST`, `SCHAAKSKOMPAS_DB_NAME`, `SCHAAKSKOMPAS_DB_USER`, `SCHAAKSKOMPAS_DB_PASSWORD`, `SCHAAKSKOMPAS_UPLOAD_DIR`, and optionally `SCHAAKSKOMPAS_DB_PORT` (defaults to `3306`). Set these outside the website files; do not commit database credentials.
5. To allow the maximum upload size, set PHP's `upload_max_filesize` to `500M`, `post_max_size` to at least `510M`, and `max_input_time` and `max_execution_time` to at least `1200` seconds. GIF files are limited to 100 MB by the application; other supported media files remain limited to 500 MB. Ensure PHP's temporary upload directory and the media volume have enough free space. If using a reverse proxy, allow request bodies up to at least `510M` and configure its upload timeout accordingly.
6. Serve the repository over HTTPS in production, with PHP enabled for the `api/` directory. The website and `/api/` must use the same origin so the session cookie is sent securely.
7. Open `Inloggen.dc.html` through Apache and create an account.

The old Supabase-specific schema is retained as `database/schema-supabase-legacy.sql` for reference only. Do not run it for this Apache/MySQL setup.

## Accounts and data

The PHP API uses prepared PDO queries, `password_hash`/`password_verify`, HttpOnly SameSite session cookies, CSRF tokens, and per-account login attempt limits. The browser never supplies the owner ID for private data access. Groups, children, training records, and guided-training notes are stored in the `user_app_data` snapshot keyed to the authenticated server-side user. Shared custom exercise categories and exercises use separate tables; a revision check protects the private snapshots from conflicting edits.

On first login, the site asks whether to upload existing browser data to an account with no server copy. If the account already has data, that copy is loaded and differing local data is kept as a downloadable browser backup. Local browser storage remains enabled as a fallback if the server is unavailable.

Custom exercise videos and animations can be uploaded by signed-in users. Video uploads accept MP4 and WebM; animation uploads accept GIF (up to 100 MB), WebP, PNG, JPG, MP4, and WebM (up to 500 MB each). Signed-in users share new exercises with all accounts; only administrators can create shared categories. Each custom category opens its own category page. Existing per-account exercise catalogs are imported to the shared catalog on first authenticated use. Shared media is served only to signed-in accounts. Only the exercise creator or a database-designated administrator may update or delete a shared item.

To grant administrator access, run a database update for the intended account, for example `UPDATE users SET is_admin = 1 WHERE email = 'coach@example.com';`. Replace the example address with the account's email.

Email verification and password-reset email delivery are not configured in this self-hosted version. Without Apache/PHP/MySQL or an account, the existing website continues to use browser storage: `schaatskompas-groepen-v1` for groups and trainings, and `schaatskompas-exercise-catalog-v1` for custom exercises and categories. Browser-only data is not shared across devices. File uploads require a signed-in account and a configured server.
