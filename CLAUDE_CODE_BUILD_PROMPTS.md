# Claude Code Build Prompts — Chrx Rental Manager (v1)

How to use this file: run the phases in order, one at a time, in the plugin's project folder. Each phase is a self-contained prompt — paste it into Claude Code as-is. Wait for a phase to complete and review its output before starting the next one; each phase assumes everything from prior phases already exists.

Before starting Phase 0, place these in the plugin project root:
- `SPEC.md` (the implementation spec)
- `designs.html` — a single standalone HTML file containing every screen design, stacked one after another on one long page (not separate files per screen). Each screen is demarcated by an HTML comment and a visible numbered label immediately above it, e.g. `<!-- 04. Dashboard -->` / `04 · Dashboard`, so a specific screen can be located by searching the file for its number/name. The full index of screens and their numbers, in the order they appear in the file, is:

  1. Login
  2. Forgot password (request)
  3a. Forgot password (request, tenant context)
  3b. Set new password
  3. Tenant invite / set-password
  04. Dashboard
  05. Properties — list
  06. Property detail
  07. Add/Edit Property form
  08. Units — list
  09. Unit detail
  10. Add/Edit Unit
  11. Tenants — list
  12. Tenant detail
  13. Add/Edit Tenant
  14. Leases — list
  15. Lease detail — charge ledger
  16. Add/Edit Lease
  17. Renew Lease
  18. Record Payment modal
  19. Receipt preview
  20. Payments — list
  21. Reports
  22. Landlord statement generator
  23. Move-out / termination
  24. Notifications / Reminders settings
  25. Staff & Roles
  26. Empty-state onboarding
  27. Landlord dashboard
  28. Landlord reports / statement download
  29. Portal home
  30. Lease details
  31. Payment history
  32. Receipt detail
  33. No active lease

  In Phase 0, extract each numbered section out of `designs.html` into its own reference file under `designs/` (e.g. `designs/04-dashboard.html`) with its inline styles intact, so later phases can reference a single screen file directly instead of re-searching the bundle each time. Keep `designs.html` itself as the untouched original reference.

## Global conventions (apply to every phase — included at the top of each prompt)

- Plugin name: **Chrx Rental Manager**. Slug: `chrx-rental-manager`. Text domain: `chrx-rental-manager`. Main plugin file: `chrx-rental-manager.php`.
- PSR-4 namespace root: `ChrxRentalManager\` mapped to `src/`. Use Composer for autoloading; `vendor/` is committed to the repo so the plugin works with zero build step on any host. Composer is also the dependency manager for any library needs (e.g. PDF generation) — do not hand-vendor libraries.
- Target PHP 8.0+, WordPress 6.0+. Use typed properties, return types, and enums where they clarify intent (e.g. status enums), but favor plain readable code over cleverness.
- Coding standard: WordPress-PHP coding conventions for anything touching WP APIs (hooks, `$wpdb`, sanitization, escaping), PSR-12 for general class/method structure. Add a `phpcs.xml` in Phase 0 and keep code passing it throughout.
- Security baseline non-negotiable in every phase: nonces on all state-changing admin actions, capability checks on every admin/AJAX/REST handler (never trust role by UI visibility alone — check server-side), `$wpdb->prepare()` for all custom queries, output escaping (`esc_html`, `esc_attr`, etc.) on all templates.
- Follow `SPEC.md` as the source of truth for data model, business rules, and edge cases. Follow the numbered screen sections extracted from `designs.html` (see the index above) as the source of truth for layout, visual hierarchy, and UI states — convert each into a PHP view template (do not just link the static HTML), preserving structure/classes so inline styles port over cleanly into the plugin's own stylesheet.
- If anything in SPEC.md or the designs is ambiguous, contradictory, or missing for the work in the current phase, stop and ask before implementing — do not guess at business logic (money/date calculations especially) or silently deviate from the spec.
- At the end of every phase, summarize: what was built, any deviations from SPEC.md/designs and why, and what's explicitly deferred to a later phase.

---

## Phase 0 — Scaffold, autoloading, activation lifecycle

Read SPEC.md in full before writing any code.

Set up the plugin skeleton:
- Main plugin file `chrx-rental-manager.php` with standard WP plugin header, PHP/WP version guard, and a bootstrap that instantiates a core `Plugin` class.
- `composer.json` with PSR-4 autoload mapping `ChrxRentalManager\\` → `src/`, dev dependencies for PHPUnit and WP coding standards (`wp-coding-standards/wpcs` or equivalent), and a placeholder for a PDF library to be added in the payments/receipts phase — don't add it yet, just leave a note in composer.json comments/README about where it'll go.
- Folder structure: `src/` (PSR-4 classes, organized by domain: `Admin/`, `Portal/`, `Data/`, `Cron/`, `Roles/`), `templates/` (PHP view files, not raw HTML), `assets/` (compiled CSS/JS, plus a `src-assets/` if any pre-processing is needed), `tests/` (PHPUnit), `designs/` (left as reference — not shipped in the production build).
- Split `designs.html` (the single bundled file with all 33+ screens) into individual reference files inside `designs/`, one per numbered screen from the index in the "Before starting Phase 0" section above (e.g. `designs/01-login.html`, `designs/04-dashboard.html`, `designs/29-portal-home.html`). Locate each screen by its HTML comment marker (`<!-- N. Screen Name -->`) and matching visible numbered label, extract that section's markup and inline styles verbatim into its own file. This turns the one long catalog page into screen-addressable references so later phases can each point at exactly the file(s) they need instead of re-searching the bundle.
- Activation hook: register the plugin's DB schema version option (empty for now, tables come in Phase 1) and the four custom roles (empty capability sets for now, filled in Phase 2).
- Deactivation hook: leave data intact (no destructive cleanup) — note in a code comment that uninstall.php (if ever added) is a separate, deliberate decision, not implied by deactivation.
- `phpcs.xml` configured for the standards described above.
- `.gitignore` appropriate for a WP plugin with committed vendor/ (so exclude typical OS/editor cruft, not vendor/).
- A `README.md` stub noting the plugin's purpose, PSR-4 structure, and how to run `composer install` / `composer test`.

Deliverable: plugin activates cleanly on a fresh WP install with no fatal errors, does nothing visible yet beyond registering roles, and `composer test` runs (even with zero tests) without error.

---

## Phase 1 — Data layer: schema, migrations, models/repositories

Read the full Data Model section of SPEC.md (section 3) before proceeding — this phase must match those tables exactly, including soft-delete columns and the audit-trail rule that financial tables (`charges`, `payments`, `receipts`) are never hard-deleted through the application layer.

Build:
- A migration runner triggered on activation and on version-bump admin_init check, creating all tables listed in SPEC.md §3 with the `wp_rm_` prefix, correct foreign keys/indexes (index at minimum: lease_id on charges/payments, property_id on units, status columns used in list filtering).
- One PSR-4 class per table under `src/Data/` (e.g. `Data\Property`, `Data\Unit`, `Data\Tenant`, `Data\Lease`, `Data\Charge`, `Data\Payment`, `Data\Receipt`, `Data\Document`) — each responsible for its own CRUD via `$wpdb`, prepared statements throughout, and enforcing the soft-delete pattern (`deleted_at`) for the four soft-deletable entities.
- A base repository/model pattern shared across these classes rather than duplicating query boilerplate — your call on the exact shape, but keep it consistent and documented.
- Enforce at this layer (not just in the UI) the invariant from SPEC.md §4.1: a unit cannot have two simultaneously active leases.
- A dev-only seed command/script (CLI or admin-only button, your call) to generate realistic sample data (a few properties, units, tenants, leases, charges, payments) for use in later phases and for design/QA comparison against the HTML mockups.
- PHPUnit tests covering: table creation, CRUD + soft delete behavior, and the "no double-active-lease" constraint.

Deliverable: schema installs correctly, models are unit-tested, seed data available for manual QA in later phases.

---

## Phase 2 — Roles, permissions, and authentication screens

Read SPEC.md §2 (Roles & Permissions) and the Authentication screens (index #1 Login, #2/#3a Forgot password request, #3b Set new password, #3 Tenant invite/set-password) in `designs/` before proceeding.

Build:
- Full capability sets for the four roles (Administrator extension, Property Manager/Staff, Landlord-Owner, Tenant) as defined in SPEC.md §2, registered on activation (extend Phase 0's stub).
- The `rm_property_staff` / `rm_property_landlords` scoping enforced via a reusable authorization helper (e.g. `Roles\Access::userCanAccessProperty( $user_id, $property_id )`) that every later phase's controllers must call — build it now so Phases 3+ have it available.
- Staff & Roles management screen matching `designs/25-staff-roles.html`: assign staff/landlord-owners to specific properties, writing to `rm_property_staff`/`rm_property_landlords` via the same helper.
- Login page matching `designs/01-login.html`: single form for all roles, redirect-by-role after authentication (Admin/Staff/Landlord → wp-admin dashboard, Tenant → the portal page). Implement as a shortcode/template so it can sit on any WP page per the "any theme" requirement from SPEC.md §4.6.
- Forgot/reset password flow matching `designs/02-forgot-password.html` and `designs/03b-set-new-password.html`, built on top of WP's native `retrieve_password`/`reset_password` mechanisms rather than a parallel custom auth system.
- Tenant portal invite flow: an admin/staff action ("Invite to Portal" per SPEC.md §4.5) that creates a WP user with the Tenant role, links `rm_tenants.wp_user_id`, and emails a set-password link; build the set-password landing screen matching `designs/03-tenant-invite.html`, framed as first-time portal setup rather than a generic WP reset screen.
- Support the SPEC.md §2 edge case: a user holding both Landlord-Owner and Tenant roles simultaneously — login redirect and any role-switch UI needed for that case.

Deliverable: all four roles can log in and land in the right place; a landlord-owner or tenant with no portal account yet cannot log in until invited; capability checks are enforced server-side, verified with tests for the access-helper logic.

---

## Phase 3 — Admin CRUD: Properties, Units, Tenants, Leases

Read SPEC.md §4.1 and the corresponding wp-admin screens in `designs/` before proceeding: #5–7 Properties (list, detail, add/edit form), #8–10 Units (list, detail, add/edit form), #11–13 Tenants (list, detail, add/edit form), #14–16 Leases (list, detail with charge ledger, add/edit form).

Build, for each of Properties, Units, Tenants, Leases:
- wp-admin list screen matching its design: search, relevant filters (status, property), pagination, using `WP_List_Table` or an equivalent consistent pattern across all four.
- Detail screen matching its design.
- Add/Edit form matching its design, with server-side validation, nonces, and capability checks (staff limited to their assigned properties per the Phase 2 access helper).
- Empty states matching `designs/26-empty-state-onboarding.html` where applicable.
- Unit status logic from SPEC.md §4.1: `occupied`/`vacant` derived from active lease presence, with manual override persistence for `maintenance`/`reserved` that isn't auto-cleared by lease changes.
- Document/photo attachment support (SPEC.md §7: WP media library, `rm_documents` table from Phase 1) on Unit, Tenant, and Lease detail screens.
- Soft-delete behavior in the UI: delete actions archive rather than destroy, plus an "Archived" filtered view to see and restore.

Deliverable: staff/admin can fully manage properties → units → tenants → leases end to end through the admin UI, matching the supplied designs, scoped correctly by role.

---

## Phase 4 — Billing cycle, renewals, and cron jobs

Read SPEC.md §4.2 and §6 (Scheduled Jobs) in full, plus `designs/15-lease-detail.html`, `designs/17-renew-lease.html`, `designs/23-move-out.html`, and `designs/24-notifications-settings.html`, before proceeding.

Build:
- The three WP-Cron jobs from SPEC.md §6: `rm_generate_monthly_charges`, `rm_send_renewal_reminders`, `rm_apply_late_fees` — scheduled on activation, unscheduled on deactivation, each implemented as its own PSR-4 class under `src/Cron/` with clear single-responsibility methods that are independently unit-testable (don't bury the logic inside the WP-Cron callback itself).
- Charge auto-generation logic exactly as described (configurable lead time before `billing_day`, default 5 days).
- Renewal reminder logic with the threshold/dedupe behavior from SPEC.md §4.2 (30/14/7 day windows, `rm_notifications_log` check to avoid duplicate sends, suppressed if already renewed).
- Late fee logic: one-time (non-recurring) fee per overdue period past the configurable grace period, respecting the "never truly delete, mark waived" rule from SPEC.md §3 if staff waive a fee.
- One-click Renew screen matching its design: pre-filled form, on confirm ends old lease (`status = renewed`) and creates new lease with `auto_renewed_from` linkage, re-arms cron for the new lease.
- Move-out / termination flow matching its design: move-out date entry, final balance calculation, deposit refund handling (`deposit_status` transitions), unit status update to `vacant`.
- Notifications/Reminders settings screen matching its design (SPEC.md §4.2/§7: configurable reminder thresholds, late fee grace period/amount, currency format) — this is where admins configure the values the cron jobs consume.
- PHPUnit tests for the billing/late-fee/renewal date math specifically — this is the highest-risk area for silent bugs per SPEC.md's own framing, so cover boundary conditions (exact grace-period-day edge, timezone handling using the WP site timezone per SPEC.md §7).

Deliverable: charges generate automatically, reminders and late fees fire correctly and without duplication, renewal and move-out flows work end to end matching designs, core date/money logic is test-covered.

---

## Phase 5 — Payments and receipts

Read SPEC.md §4.3 in full plus `designs/18-record-payment.html`, `designs/19-receipt-preview.html`, and `designs/20-payments-list.html` before proceeding.

Build:
- Record Payment form/modal matching its design: amount, method (cash/bank_transfer/mtn_momo/airtel_money/other), date, note; live balance calculation against the selected charge; partial payment handling per SPEC.md §4.3.
- Overpayment handling exactly as specified: excess becomes an unallocated credit auto-applied to the next generated charge, not left dangling.
- PDF receipt generation on payment save — this is where the deferred PDF library from Phase 0's composer.json gets added (e.g. dompdf or mpdf; pick one, document the choice and why). Receipt matches the design's content (receipt number, tenant/unit/property, amount, method, date, running balance after payment).
- Automatic email of the PDF receipt to the tenant's registered email, with graceful handling/logging (via `rm_notifications_log`) when a tenant has no email on file.
- Payments list screen matching its design: filter by property/date/method, CSV export action.
- Allow recording a payment against an ended/archived lease (closing-out settlement per SPEC.md §4.3 edge case), visually flagged as distinct from current activity per the design.

Deliverable: staff can record any payment scenario (full, partial, overpaid, against a closed lease), a correct PDF receipt is generated and emailed automatically, payments are exportable.

---

## Phase 6 — Landlord dashboard and reporting

Read SPEC.md §4.4 in full plus `designs/04-dashboard.html`, `designs/27-landlord-dashboard.html`, `designs/21-reports.html`, `designs/22-landlord-statement-generator.html`, and `designs/28-landlord-reports.html` before proceeding.

Build:
- Shared dashboard component (per SPEC.md §4.4's explicit instruction to reuse one component with role-scoped queries, not two separate dashboards) showing occupancy rate, total outstanding balance, upcoming lease expirations, recent payments — property-filterable for Admin/Staff, auto-scoped to owned properties for Landlord-Owner via the Phase 2 access helper.
- Reports screen matching its design: occupancy report, outstanding balances report, payment history report, each with CSV export.
- Landlord statement PDF generator matching its design: property/landlord + date range selection, preview, generate — reusing the Phase 5 PDF pipeline rather than adding a second one.
- Enforce the SPEC.md §4.4 edge case at the query layer with a test: a Landlord-Owner querying reports/dashboard data must only ever receive rows for properties in their `rm_property_landlords` assignment, verified by an automated test that attempts cross-owner access and asserts it's blocked/empty, not just hidden in the UI.

Deliverable: dashboards and reports match designs and are provably scoped correctly by role, exports and statement PDFs work.

---

## Phase 7 — Tenant Self-Service Portal (front-end)

Read SPEC.md §4.5 and §4.6 in full plus `designs/29-portal-home.html`, `designs/30-portal-lease-details.html`, `designs/31-portal-payment-history.html`, `designs/32-portal-receipt-detail.html`, and `designs/33-portal-no-active-lease.html` before proceeding.

Build:
- `[rental_portal]` shortcode/block rendering the full portal as a theme-agnostic front-end page, mobile-first per SPEC.md §4.6 (stacked-card payment history on narrow viewports, no forced horizontal-scroll tables).
- Portal home/balance overview matching its design, including overdue/late-fee flag display.
- Lease details view matching its design.
- Payment history view with receipt download links matching its design.
- Receipt detail/download view matching its design.
- "No active lease yet" empty state matching its design, for tenants invited before move-in.
- Strict view-only enforcement per SPEC.md §4.5: no data-entry affordances anywhere in the portal, with copy explicitly directing tenants to contact their property manager for payment ("Contact your property manager to make a payment" per SPEC.md) rather than presenting an inert or broken pay button.
- Server-side scoping so a logged-in tenant can only ever query their own `rm_tenants`/`rm_leases` records — verified with a test attempting to access another tenant's data by ID manipulation.

Deliverable: tenant portal fully matches designs, works responsively across the three breakpoints, and is provably scoped to the logged-in tenant only.

---

## Phase 8 — Polish, responsive QA, and full regression pass

Before this phase, all 33+ screens split out from `designs.html` into `designs/` should exist and be functional. This phase is verification, not new features.

Do:
- Walk every numbered screen file in `designs/` against the built plugin and produce a checklist report of any visual or functional gaps, resolving the straightforward ones directly and flagging anything ambiguous for review rather than guessing.
- Verify responsive behavior at the three breakpoints from SPEC.md §4.6 (mobile ~375px, tablet ~768px, desktop ~1280px) for both the wp-admin screens and the tenant portal.
- Run the full PHPUnit suite and `phpcs` and fix any failures.
- Re-verify the two hard security invariants across the whole plugin: every state-changing action has a nonce + capability check, and every data query is scoped server-side by role (grep for any query missing the Phase 2 access-helper call where one is warranted).
- Confirm the SPEC.md §8 "Known v1 Limitations" are true of the built plugin (no online payment collection, one tenant per lease, single currency, no maintenance requests/messaging, no license gating) — i.e. nothing was silently over-built or under-built relative to scope.
- Produce a short final report: what's built, test coverage summary, any deviations from SPEC.md/designs made along the way and why, and a clearly labeled list of anything deferred.

Deliverable: v1 complete, tested, matches SPEC.md and `designs.html`, ready for manual client-facing QA.
