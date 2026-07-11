# Chrx Rental Manager

A self-contained WordPress plugin for property management companies: properties, units, tenants, leases, payment recording, PDF receipts, landlord dashboards/reporting, and a mobile-first tenant self-service portal.

See `SPEC.md` for the full implementation spec (data model, business rules, edge cases) and `designs/` for the per-screen UI reference (split from the original `designs.html` bundle — one file per numbered screen).

## Structure

- `chrx-rental-manager.php` — plugin bootstrap (WP header, version guard, activation/deactivation lifecycle).
- `src/` — PSR-4 classes under the `ChrxRentalManager\` namespace, organized by domain:
  - `Admin/` — wp-admin screens and controllers.
  - `Portal/` — front-end tenant portal.
  - `Data/` — table models/repositories, migrations.
  - `Cron/` — scheduled jobs (charge generation, renewal reminders, late fees).
  - `Roles/` — role/capability registration and the property-scoping authorization helper.
- `templates/` — PHP view files (not raw HTML).
- `assets/` — compiled CSS/JS shipped with the plugin; `assets/src-assets/` holds any pre-processed source if needed later.
- `tests/` — PHPUnit tests.
- `designs/` — per-screen HTML reference extracted from `designs.html` (not shipped in the production build).

## Development

```bash
composer install
composer test   # PHPUnit (unit tests — no DB required)
composer cs      # phpcs
composer cbf      # phpcbf (auto-fix)
```

`vendor/` is committed to the repository so the plugin works with zero build step on any host — do not add `vendor/` to `.gitignore`.

### Integration tests

`tests/Integration` exercises real `$wpdb` behavior (table creation, CRUD, soft delete, the no-double-active-lease constraint) against a live WordPress + MySQL environment — there's no isolated WP test-suite scaffold in this project, so point it at a real local install. Each test runs inside a transaction that's rolled back afterward, so it's safe to run against a dev site's existing database.

```bash
WP_LOAD_PATH="/absolute/path/to/wp-load.php" \
DB_SOCKET="/path/to/mysqld.sock" \
composer test:integration
```

`DB_SOCKET` is only needed if your `DB_HOST` doesn't resolve to the right MySQL instance by default (e.g. Local by Flywheel runs one MySQL per site on its own socket) — omit it if a plain WP-CLI/`wp-load.php` bootstrap already connects fine.

## PDF library

A PDF library is required starting in the Payments & Receipts phase (PDF receipts) and reused for landlord statements. Not yet added — see the `extra.chrx-rental-manager` note in `composer.json` for the candidate and rationale, finalized in that phase.

## Roadmap (v2, not built in v1 — see SPEC.md §8/§9)

- Online payment gateway integration (mobile money API)
- Co-tenant / multi-tenant-per-lease support
- Multi-currency per lease
- Maintenance requests / in-portal messaging
- License-key activation / auto-update system
- Multi-company SaaS on one WP install
