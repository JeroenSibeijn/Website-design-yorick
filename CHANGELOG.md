# Changelog

Notable changes to Schaatskompas are recorded here.

## [Unreleased]

### Added

- Added a self-hosted PHP API for account registration, login, logout, and per-user data synchronization with MySQL/MariaDB.
- Added revision checks for concurrent edits and downloadable local backups when browser data differs from account data.
- Added a shared navigation stylesheet so the main pages use a consistent menu layout, including the login page.

### Changed

- Kept browser storage available as a local fallback when the account server is unavailable or not configured.
- Updated the setup documentation for Apache, PHP, MySQL/MariaDB, HTTPS, and server-side environment variables.

### Fixed

- Removed the border from the login link on the groups page.
- Standardized the logo accent color and navigation sizing across the regular site pages.
