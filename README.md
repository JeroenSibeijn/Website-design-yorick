# Schaatskompas

Schaatskompas is a Dutch-language skating coaching and training planner built with HTML, CSS, JavaScript, PHP, and MySQL/MariaDB.

## Run the website

There is no JavaScript build step or package installation. Open `Main.dc.html` for the local browser-only mode, or configure Apache to serve the repository root for PHP accounts and server synchronization. Keep the repository root as the document root; exercise pages load shared files and the API through relative paths.

## Main pages

- `Main.dc.html` - season overview and upcoming trainings.
- `Groepen.dc.html` - groups, children, progress, and training records.
- `Builder.dc.html` - build and save a training for a group.
- `Oefeningen/Oefeningen.dc.html` - exercise catalogue index.
- `Oefeningen/` - exercise categories and exercise detail pages.
- `OpHetIjs.dc.html` - guided training mode, per-part notes, and fullscreen animations.
- `Inloggen.dc.html` - account registration, login, sync, backup download, and sign-out.

## Configure the self-hosted server

1. Use Apache with PHP 7.4+ and the `pdo_mysql` extension enabled.
2. Create a MySQL/MariaDB database and an application database user with access only to that database. Run `database/schema.sql`.
3. Configure Apache's PHP environment with `SCHAAKSKOMPAS_DB_HOST`, `SCHAAKSKOMPAS_DB_NAME`, `SCHAAKSKOMPAS_DB_USER`, `SCHAAKSKOMPAS_DB_PASSWORD`, and optionally `SCHAAKSKOMPAS_DB_PORT` (defaults to `3306`). Set these outside the website files; do not commit database credentials.
4. Serve the repository over HTTPS in production, with PHP enabled for the `api/` directory. The website and `/api/` must use the same origin so the session cookie is sent securely.
5. Open `Inloggen.dc.html` through Apache and create an account.

The old Supabase-specific schema is retained as `database/schema-supabase-legacy.sql` for reference only. Do not run it for this Apache/MySQL setup.

## Accounts and data

The PHP API uses prepared PDO queries, `password_hash`/`password_verify`, HttpOnly SameSite session cookies, CSRF tokens, and per-account login attempt limits. The browser never supplies the owner ID for data access. Groups, children, training records, guided-training notes, and custom exercise categories are stored as the existing app data snapshot in `user_app_data`, keyed to the authenticated server-side user. A revision check detects concurrent edits instead of silently replacing a newer server copy.

On first login, the site asks whether to upload existing browser data to an account with no server copy. If the account already has data, that copy is loaded and differing local data is kept as a downloadable browser backup. Local browser storage remains enabled as a fallback if the server is unavailable.

Email verification and password-reset email delivery are not configured in this self-hosted version. Without Apache/PHP/MySQL or an account, the existing website continues to use browser storage: `schaatskompas-groepen-v1` for groups and trainings, and `schaatskompas-exercise-catalog-v1` for custom exercises and categories. Browser-only data is not shared across devices.
