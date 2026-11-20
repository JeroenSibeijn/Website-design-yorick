# Schaatskompas

Schaatskompas is a Dutch-language skating coaching and training planner built with static HTML, CSS, and JavaScript.

## Getting started

There is no build step or package installation.

1. Open `Main.dc.html` in a modern browser.
2. Use the navigation to manage groups, build trainings, browse exercises, or open the ice mode.

If your browser restricts local-file storage or navigation, serve the repository root with a local static web server and open `Main.dc.html` through that server. Keep the repository root as the web root: the exercise pages use `../` paths to load shared files and top-level pages.

## Main pages

- `Main.dc.html` - season overview and upcoming trainings.
- `Groepen.dc.html` - groups, children, progress, and training records.
- `Builder.dc.html` - build and save a training for a group.
- `Oefeningen/Oefeningen.dc.html` - exercise catalogue index.
- `Oefeningen/` - exercise categories and exercise detail pages.
- `OpHetIjs.dc.html` - guided training mode, per-part notes, and fullscreen animations.
- `Inloggen.dc.html` - login and registration portal mockup.

Shared exercise-page styling and catalogue behavior live in `exercise-pages.css` and `exercise-catalog.js` at the repository root.

## Data and backend status

Group, child, progress, and training data are stored in the browser's local storage under `schaatskompas-groepen-v1`. Custom exercise catalogue data uses `schaatskompas-exercise-catalog-v1`. This data is local to the browser profile and is not synced between users or devices.

The login page is a prototype and does not send or store credentials. `database/schema.sql` contains starter PostgreSQL/Supabase schema and policies, but live authentication and database synchronization are not connected to the website.
