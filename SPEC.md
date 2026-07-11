# Rental Management WordPress Plugin — Implementation Spec

Version: 1.0 (v1 scope)
Date: 2026-07-10

## 1. Overview

A commercial WordPress plugin sold per-site to property management companies. Each installation is a standalone, self-contained account: one company, its staff, its landlords, its properties/units/tenants. No cross-site data sharing and no license-key gating in v1 — distributed as a plain plugin package.

Standalone by design: ships its own admin screens (native WP admin UI, not CPT-generated) and its own front-end tenant portal via shortcode/block, so it works on any active theme.

### 1.1 Modules in scope

1. Tenant & Unit Management
2. Lease Tracking & Automated Renewal Reminders
3. Payment Recording & Receipt Generation
4. Landlord Dashboard & Reporting
5. Tenant Self-Service Portal
6. Mobile-Responsive Design

### 1.2 Explicitly out of scope for v1

- Online payment gateway integration (mobile money API, Stripe, etc.) — v1 is manual recording of cash/bank/mobile-money payments by staff
- Maintenance request / repair ticketing
- In-portal messaging between tenant and staff
- Multi-currency per lease (single currency per site)
- Multiple co-tenants per lease
- License key activation / auto-update system
- Multi-company / multi-tenant SaaS on one WP install

These are worth flagging as a v2 roadmap in the README but should not be built now — each adds meaningful surface area (payment gateway = PCI/webhook handling; SaaS = full data partitioning rework).

## 2. Roles & Permissions

Built on top of WordPress's role/capability system with four custom roles:

| Role | Scope | Key capabilities |
|---|---|---|
| Administrator (existing WP role, extended) | Full account | All capabilities; manages plugin settings, staff accounts, billing config |
| Property Manager / Staff | Assigned properties only | CRUD tenants, units, leases, payments on properties they're assigned to; cannot change plugin settings or manage other staff |
| Landlord-Owner | Their own properties only | Read-only: dashboard, reports, statements for properties they own. Cannot edit tenants/leases/payments |
| Tenant | Their own lease only | Read-only portal: balance, lease details, payment history, receipt downloads |

**Design decision:** Staff and Landlord-Owner are assigned to specific properties via a join table (`rm_property_staff`, `rm_property_landlords`), not a blanket account-wide permission. This is the realistic model for a management company running multiple owners' buildings — an owner must never see another owner's financials.

**Edge case:** A user could theoretically be both a Landlord-Owner and Tenant (e.g. an owner also rents a unit elsewhere in the portfolio). Support this by allowing a WP user to hold multiple plugin roles simultaneously; the portal UI switches context if a user has more than one linked record.

## 3. Data Model (custom database tables)

Custom tables were chosen over CPT+meta for relational integrity and reporting performance on financial data — joins across leases/payments/units are frequent (dashboard, statements) and meta-table queries don't scale well for this.

Prefix: `wp_rm_`

- **rm_properties**: id, name, address, city, notes, created_at
- **rm_units**: id, property_id (FK), unit_label, bedrooms, rent_amount, status (`vacant` / `occupied` / `maintenance` / `reserved`), notes, created_at
- **rm_tenants**: id, wp_user_id (FK, nullable until portal account created), full_name, phone, email, national_id, status (`active` / `former`), created_at
- **rm_leases**: id, unit_id (FK), tenant_id (FK), start_date, end_date, rent_amount, billing_day, deposit_amount, deposit_status (`unpaid`/`paid`/`refunded`/`forfeited`), status (`active`/`ended`/`renewed`), auto_renewed_from (FK, nullable), created_at
- **rm_charges**: id, lease_id (FK), period_start, period_due_date, amount_due, type (`rent`/`late_fee`/`deposit`), status (`unpaid`/`partial`/`paid`), created_at — this is the ledger line auto-generated each billing cycle
- **rm_payments**: id, lease_id (FK), charge_id (FK, nullable — payment can be unallocated/advance), amount, method (`cash`/`bank_transfer`/`mtn_momo`/`airtel_money`/`other`), reference_note, recorded_by (wp_user_id), receipt_id (FK), paid_at, created_at
- **rm_receipts**: id, payment_id (FK), receipt_number, pdf_path, emailed_at, created_at
- **rm_documents**: id, entity_type (`lease`/`unit`/`tenant`), entity_id, attachment_id (WP media library ID), label, uploaded_by, created_at
- **rm_property_staff**: property_id, wp_user_id
- **rm_property_landlords**: property_id, wp_user_id
- **rm_notifications_log**: id, type, recipient, entity_id, sent_at, status — audit trail for reminder/notification emails, useful for support debugging ("did the tenant actually get the reminder")

**Soft delete:** `rm_tenants`, `rm_leases`, `rm_units`, `rm_properties` all get a `deleted_at` nullable timestamp rather than hard deletes. All list queries filter `deleted_at IS NULL` by default; an "Archived" view lets admins see and restore soft-deleted records. Financial tables (`rm_charges`, `rm_payments`, `rm_receipts`) are never deletable through the UI at all — only correctable via a reversing/adjustment entry, to preserve an audit trail. This matters because a management company's books need to reconcile even after a tenant relationship ends.

## 4. Module Specs

### 4.1 Tenant & Unit Management

**Data entry flow:** Staff creates a Property first, then adds Units under it (label, bedrooms, base rent, status). Tenant records are created independently, then linked to a unit via a Lease (a tenant with no active lease is just a prospect/former-tenant record, still searchable).

**Unit status is derived, with manual override:** Status shows `occupied` automatically while an active lease exists on the unit; `vacant` when no active lease. Staff can manually set `maintenance` or `reserved`, which the system will not auto-clear until staff clears it, even if no lease exists — this prevents a unit under renovation from silently reappearing as "available" in vacancy reports.

**Edge cases:**
- Deleting a unit that has lease history: blocked, must be archived (soft delete) instead — history must remain queryable in reports.
- Two active leases pointing at the same unit: prevented at the DB/application layer — creating a new lease on an occupied unit requires ending the existing lease first (or is blocked with a clear error naming the conflicting lease).
- Searching/filtering: tenant list and unit list both need search (name/phone/unit label) and filter (status, property) since a "few hundred units" account will not be scannable as a flat list.

**Tradeoff:** One tenant per lease/unit (no co-tenants) keeps the ledger and portal-access model simple — a single balance, a single login. The cost is that shared households need a workaround (list one tenant as primary, note the other in `notes`); this is an acceptable v1 limitation given the scope decision made, but worth documenting as a known limitation so support requests are anticipated.

### 4.2 Lease Tracking & Automated Renewal Reminders

**Billing cycle generation:** On lease creation (and on each renewal), a WP-Cron job (`rm_generate_monthly_charges`, runs daily) checks all active leases and auto-creates the next period's `rm_charges` row a configurable number of days before the `billing_day` (default: 5 days ahead), so tenants and staff see the upcoming charge before it's due, not just on the due date.

**Renewal reminders:** A daily WP-Cron job scans leases with `end_date` within a configurable window (default 30/14/7 days) and, if not already sent for that threshold (tracked via `rm_notifications_log`), sends:
- Email to assigned staff and the landlord-owner of that property
- Email to the tenant (optional per-account setting — some companies may not want tenants prompted directly)
- In-portal notification banner in both wp-admin (staff/landlord) and the tenant portal

**One-click renewal:** From the lease detail screen, a "Renew" button opens a pre-filled form (same unit, tenant, rent, duration) that staff can edit before confirming. Confirming ends the old lease (`status = renewed`), creates a new `rm_leases` row with `auto_renewed_from` pointing at the old one, and re-arms the charge-generation cron for the new lease. This linkage matters for reporting continuity (a landlord statement spanning a renewal shouldn't show a gap or a fake "move-out").

**Edge cases:**
- Lease expires with no renewal action taken: unit does NOT auto-vacate. Status stays `occupied` until staff explicitly ends the lease (real-world tenants often overhold on expired leases) — but the dashboard flags it clearly as "expired, not renewed" so it doesn't get silently lost.
- Reminder fires but the lease was renewed in the meantime: cron checks `status = active` before sending, so a same-day renewal suppresses the reminder.
- Time zone: all due-date/reminder calculations should use the WordPress site timezone setting, not server UTC, to avoid off-by-one-day bugs around midnight for reminder scheduling.

### 4.3 Payment Recording & Receipt Generation

**Recording flow:** Staff opens a lease, sees the ledger (list of `rm_charges` with amount due / paid / balance), clicks "Record Payment" against a specific charge (or as an unallocated advance payment if paying ahead), enters amount, method, date, optional note. Partial amounts are accepted — the charge status flips to `partial` and the remainder stays owing, carried forward and visible in the tenant's running balance rather than disappearing.

**Receipt generation:** On saving a payment, the system immediately generates a PDF receipt (receipt number, tenant/unit/property details, amount, method, date, running balance after payment), stores it, and emails it to the tenant's registered email if present. The receipt is also downloadable from both the staff lease screen and the tenant portal.

**Late fees:** Configurable per property (or account-wide default): grace period in days + fee (flat amount or % of rent). A daily cron checks charges past `period_due_date + grace_period` that are still `unpaid`/`partial` and haven't already had a fee applied for that period, and inserts a one-time `rm_charges` row of type `late_fee`. It does not recur/escalate — matches the decision to keep this predictable and avoid runaway penalty bugs. Staff can waive/delete a late fee charge manually (with the deletion following the same "never truly delete financial rows" rule — a waived fee is marked `status = waived`, not removed).

**Edge cases:**
- Overpayment: if a payment exceeds the charge balance, the excess is recorded as a credit / unallocated payment automatically applied to the next generated charge, not left as a random overage.
- Payment recorded against a charge on an ended/archived lease: allowed (e.g. settling a final balance after move-out) but flagged distinctly in the UI so it's clear this is a closing-out entry, not current activity.
- Currency formatting is single, account-wide, admin-configurable (symbol + decimal/thousands convention) applied consistently across dashboard, receipts, and portal.

### 4.4 Landlord Dashboard & Reporting

**Dashboard (wp-admin, role-scoped):** occupancy rate (occupied/total units, filterable by property), total outstanding balance, upcoming lease expirations, recent payments. Landlord-Owners see the identical dashboard shape but scoped only to properties in `rm_property_landlords` for their user ID — same UI component, different data query, to avoid maintaining two dashboards.

**Reports & export:**
- CSV export of any filtered list view (payments in a date range, outstanding balances, occupancy by property)
- PDF statement generator: per-property or per-landlord, for a date range — a formatted summary (income, outstanding, occupancy) suitable for sending to an owner. This reuses the same PDF library as receipts (see 4.3) to avoid a second PDF dependency.

**Edge case:** A landlord-owner with properties managed by multiple staff should only ever see aggregated financials for their own properties, never staff performance data or other owners' units — enforce this at the query layer (always join through `rm_property_landlords`), not just by hiding UI elements, since hiding UI without a server-side check is a real data leak risk in this kind of role model.

### 4.5 Tenant Self-Service Portal

**Access provisioning:** Staff creates the tenant record and, from the tenant screen, clicks "Invite to Portal" — this creates a WP user (role: Tenant), links `rm_tenants.wp_user_id`, and emails the tenant a set-password link (standard WP `retrieve_password` flow, not a plaintext password in email). A tenant with no lease yet can still be invited (useful for onboarding before move-in), but the portal shows "no active lease" state gracefully rather than erroring.

**Portal content (view-only, per the defined scope):**
- Current balance (charges vs. payments, clearly showing any partial/overdue amounts and late fees)
- Lease details (unit, property, term dates, rent amount, deposit status)
- Payment history with receipt download links

**Front-end implementation:** delivered via a shortcode/block (`[rental_portal]`) placed on any page by the site owner, so it inherits the active theme's header/footer/styling rather than shipping a competing full-page template — this is what makes "any theme" compatibility realistic.

**Edge case:** Tenant portal must never expose data entry — no edit/pay buttons in v1 since there's no payment gateway; this should be made explicit in the UI copy ("Contact your property manager to make a payment") so tenants don't expect an online-pay button that doesn't exist.

### 4.6 Mobile-Responsive Design

Two distinct surfaces, both need responsive treatment but with different approaches:

- **Tenant portal (front-end):** Built mobile-first since a large share of tenants in this market will access it primarily via phone browsers, often on slower connections. Single-column layouts, no data tables that require horizontal scroll (use stacked card layouts for payment history on narrow viewports), lightweight assets.
- **Staff/admin screens (wp-admin):** WordPress admin is already responsive at a baseline level; the plugin's custom admin screens (dashboards, ledgers) should follow WP admin's existing responsive patterns and avoid wide fixed-width tables that break on tablet-sized staff devices, since staff frequently do site visits with a tablet.

No native app — this is responsive web only, which matches the "full functionality on phones, tablets, desktops" requirement without a separate app maintenance burden.

## 5. Notifications Summary

| Event | Recipients | Channel |
|---|---|---|
| Lease expiring (30/14/7 days) | Staff, landlord-owner, tenant (optional) | Email + in-portal/in-dashboard banner |
| Payment recorded | Assigned staff | Email |
| Charge overdue past grace period | Assigned staff | Email |
| Portal invite sent | Tenant | Email (set-password link) |
| Receipt generated | Tenant | Email (PDF attached) |

All outbound notifications are logged in `rm_notifications_log` for auditability and to prevent duplicate sends from overlapping cron runs.

## 6. Scheduled Jobs (WP-Cron)

Given the small-to-medium scale target (up to a few hundred units on standard/shared hosting), standard WP-Cron is sufficient — no real server cron requirement — but the plugin should document in its README that site owners with low-traffic sites (which don't reliably trigger WP-Cron's page-load-based execution) should set up a real cron hitting `wp-cron.php`, since reminder and billing accuracy depends on these jobs actually firing daily.

- `rm_generate_monthly_charges` (daily): create upcoming period charges per lease
- `rm_send_renewal_reminders` (daily): scan expiring leases, send reminders per threshold
- `rm_apply_late_fees` (daily): scan overdue charges past grace period, apply one-time fee

## 7. Non-Functional Requirements

- **Data integrity over convenience:** financial rows (`rm_charges`, `rm_payments`, `rm_receipts`) are append-only/soft-delete; corrections happen via adjustment entries, never silent edits, so a landlord statement always reconciles to what was actually recorded at the time.
- **Server-side authorization:** every data query is scoped by role at the query layer (property assignment for staff/landlords, tenant ID for tenants) — not just conditional UI rendering.
- **File storage:** lease/tenant document uploads and generated receipts/statements use the WordPress media library and standard uploads directory, respecting existing site file-permission conventions rather than a custom storage path.
- **Localization-ready:** all UI strings wrapped for translation (standard WP i18n functions), even though multi-currency/multi-language isn't in v1 scope — cheap to do now, expensive to retrofit.
- **Timezone:** all date/time logic uses the WP site timezone setting.

## 8. Known v1 Limitations (documented, not silently omitted)

- No online payment collection — all payments are manually recorded after the fact
- One tenant per lease (no co-tenant/roommate support)
- Single currency per site
- No maintenance requests or in-portal messaging
- No license-key gating — plugin is distributed as a standard package
- Not designed for multi-company SaaS on a single WP install; each install serves one management company

## 9. Open Items for Next Round (if scope expands later)

- Payment gateway integration (mobile money API) would be the highest-value v2 addition given this market — flagged here so the data model (`rm_payments.method`, `rm_charges` as distinct from `rm_payments`) was deliberately built to accommodate an automated payment webhook later without restructuring.
- Co-tenant support would require restructuring `rm_leases` to a many-to-many with tenants rather than a single `tenant_id` FK — worth keeping in mind if this comes up, since it's a breaking schema change, not an additive one.
