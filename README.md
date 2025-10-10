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
5. (Optional) Rebuild the database from scratch, loading migrations, demo data, and an admin user:
   ```sh
   make rebuild
   ```
   Pass `ARGS="--no-dummy"` or `ARGS="--no-admin"` to the make target (or run `php scripts/rebuild_app.php` directly) if you want to exclude demo rows or the admin seeding step.
6. Launch the development web server:
   ```sh
   make run
   ```
7. Visit [http://localhost:8080](http://localhost:8080) and sign in with the seeded administrator credentials.

## Configuration

Environment variables are read directly via `getenv`. Set them in your shell or a `.env` file (loaded by your web server):

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `BASE_URL` (defaults to `/`)
- `APP_DEBUG` (`1` to show errors in development, otherwise disable display of errors)

### Single sign-on

Administrators can configure Google Workspace or Microsoft Entra ID (Azure AD) authentication under **Administration → Branding & Landing**. Provide the client ID, client secret, and (for Microsoft) the tenant identifier. The OAuth redirect URL is `<BASE_URL>/oauth.php?provider=<google|microsoft>&action=callback`; ensure this is registered with each provider. When enabled, the login page renders "Sign in with Google" or "Sign in with Microsoft" buttons alongside the classic username/password form.

## Internationalisation

Translations live in `lang/*.json`. Users can switch between English, French, and Amharic via the language selector. Preferences persist in the session and a cookie. To add a language:

1. Create `lang/<code>.json` with translation keys.
2. Add the language code to `AVAILABLE_LOCALES` in `i18n.php`.

## Database migrations & data

- `init.sql` – bootstrap schema.
- `migration.sql` – incremental changes when upgrading existing databases.
- `dummy_data.sql` – optional demo users and responses. Remove with `dummy_data_cleanup.sql` if needed.

## Questionnaire import

Administrators can upload FHIR `Questionnaire` resources (JSON or XML) from
**Administration → Manage Questionnaires**. The importer accepts standalone
Questionnaire resources or Bundles that contain them. Items with
`<type>group</type>` (or child `item` nodes) become sections in the application,
and every nested item is imported as a question in the same order as the source
file. A sample XML template that demonstrates the grouping structure is
available at `assets/samples/sample_questionnaire_template.xml`.

## Development tooling

- `make lint` – run `php -l` across all PHP files.
- GitHub Actions workflow `.github/workflows/ci.yml` lints PHP and checks MySQL availability.

## Quality and compliance

Refer to [`docs/quality_assurance.md`](docs/quality_assurance.md) for the
project's quality management and compliance framework. The checklist aligns the
delivery workflow with ISO/IEC 12207 (software life cycle), ISO/IEC 25010
(product quality), ISO/IEC 27001 (information security), and related standards.

## Default navigation

- `/index.php` – login
- `/my_performance.php` – personal performance hub (default landing page after login)
- `/submit_assessment.php` – assessment submission
- `/admin/*` – administration

## Progressive Web App assets

`manifest.webmanifest` and `service-worker.js` are referenced with the configured base URL to ensure they resolve when deployed in a subdirectory.
