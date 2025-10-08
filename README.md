# HRassess v3.0.0

Performance assessment portal built with PHP and MySQL.

## Quick start

1. Copy `.env.example` to `.env` and adjust values as needed.
2. Create the database schema:
   ```sh
   mysql -u root -p < init.sql
   ```
3. (Optional) Load demo content:
   ```sh
   mysql -u root -p < dummy_data.sql
   ```
4. Seed a default administrator account:
   ```sh
   make seed-admin
   ```
   The command prints the generated password to the console.
5. Launch the development web server:
   ```sh
   make run
   ```
6. Visit [http://localhost:8080](http://localhost:8080) and sign in with the seeded administrator credentials.

## Configuration

Environment variables are read directly via `getenv`. Set them in your shell or a `.env` file (loaded by your web server):

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `BASE_URL` (defaults to `/`)
- `APP_DEBUG` (`1` to show errors in development, otherwise disable display of errors)

## Internationalisation

Translations live in `lang/*.json`. Users can switch between English, French, and Amharic via the language selector. Preferences persist in the session and a cookie. To add a language:

1. Create `lang/<code>.json` with translation keys.
2. Add the language code to `AVAILABLE_LOCALES` in `i18n.php`.

## Database migrations & data

- `init.sql` – bootstrap schema.
- `migration.sql` – incremental changes when upgrading existing databases.
- `dummy_data.sql` – optional demo users and responses. Remove with `dummy_data_cleanup.sql` if needed.

## Development tooling

- `make lint` – run `php -l` across all PHP files.
- GitHub Actions workflow `.github/workflows/ci.yml` lints PHP and checks MySQL availability.

## Default navigation

- `/index.php` – login
- `/dashboard.php` – user dashboard
- `/submit_assessment.php` – assessment submission
- `/admin/*` – administration

## Progressive Web App assets

`manifest.webmanifest` and `service-worker.js` are referenced with the configured base URL to ensure they resolve when deployed in a subdirectory.
