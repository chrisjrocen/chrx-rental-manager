# Rental Management WordPress Plugin — Implementation Spec

Version: 2.0 (v1 scope + v2 client-feedback features)
Date: 2026-07-21

> **v2 note for implementers:** v1 is already built against this document's earlier revision. Section 10 lists every v2 change and the required data migrations (including the `reserved` → `booked` status rename and the removal of the single-active-lease-per-unit invariant). All v1 behavior not explicitly changed in this revision remains as specified.

## 1. Overview

A commercial WordPress plugin sold per-site to property management companies. Each installation is a standalone, self-contained account: one company, its staff, its landlords, its properties/units/tenants. No cross-site data sharing and no license-key gating — distributed as a plain plugin package.

Standalone by design: ships its own admin screens (native WP admin UI, not CPT-generated) and its own front-end tenant portal via shortcode/block, so it works on any active theme.

### 1.1 Modules in scope

1. Tenant & Unit Management (v2: capacity, amenities, next of kin, `booked` status)
2. Lease Tracking & Automated Renewal Reminders (v2: flexible billing cycles, multiple leases per unit)
3. Payment Recording & Receipt Generation (v2: printable receipts for desktop and Bluetooth thermal printers)
4. Landlord Dashboard & Reporting (v2: expenses and net income on statements)
5. Tenant Self-Service Portal (v2: Pay Now via Nylon Pay, give move-out notice)
6. Mobile-Responsive Design
7. **v2 — Expense Management:** record one-off and recurring expenses against a unit, property, or the whole account; expense reports and net-income statements
8. **v2 — WhatsApp Communications:** all system notifications (invites, reminders, receipts, alerts) deliverable via WhatsApp in addition to email, through a pluggable channel layer
9. **v2 — Online Payments via Nylon Pay:** tenant-initiated Pay Now from the portal and staff-initiated payment requests, auto-reconciled by webhook
10. **v2 — Custom Alerts:** staff and landlords compose scheduled/recurring alerts attached to a property or unit, with configurable recipients and channels
11. **v2 — Move-Out Notice Policy:** configurable notice period (default 2 months) with notice tracking and earliest-move-out computation

### 1.2 Explicitly out of scope

- Maintenance request / repair ticketing
- Two-way messaging (WhatsApp is **outbound only** — inbound replies are not received or processed by the plugin)
- Multi-currency per lease (single currency per site; Nylon Pay collections use the site currency and it must be a currency Nylon Pay supports, e.g. UGX)
- Payment gateways other than Nylon Pay (the abstraction should not preclude them, but only Nylon Pay is built)
- License key activation / auto-update system
- Multi-company / multi-tenant SaaS on one WP install
- Native mobile app (responsive web only)

## 2. Roles & Permissions

Built on top of WordPress's role/capability system with four custom roles:

| Role | Scope | Key capabilities |
|---|---|---|
| Administrator (existing WP role, extended) | Full account | All capabilities; manages plugin settings, staff accounts, billing config, Nylon Pay & WhatsApp credentials |
| Property Manager / Staff | Assigned properties only | CRUD tenants, units, leases, payments, **expenses**, **custom alerts** on properties they're assigned to; can send Nylon Pay payment requests; cannot change plugin settings or manage other staff |
| Landlord-Owner | Their own properties only | Read-only dashboard, reports, statements **plus one write capability: create/manage custom alerts on their own properties** |
| Tenant | Their own lease(s) only | Portal: balance, lease details, payment history, receipt downloads, **Pay Now via Nylon Pay**, **submit move-out notice** |

**Design decision:** Staff and Landlord-Owner are assigned to specific properties via join tables (`rm_property_staff`, `rm_property_landlords`), not a blanket account-wide permission. An owner must never see another owner's financials.

**v2 change — Landlord write access:** Landlord-Owner remains read-only for all financial and tenancy data. The single exception is custom alerts (module 10): landlords can create, edit, and delete alerts attached to their own properties/units. This is enforced with a dedicated capability (`rm_manage_own_alerts`) and the same property-scoping helper as everything else.

**Edge case:** A user could be both a Landlord-Owner and Tenant. Support multiple plugin roles per WP user; the portal UI switches context if a user has more than one linked record.

**WhatsApp number on users:** Every person-type record (staff and landlord WP users via user meta; tenants via `rm_tenants.whatsapp_number`) gets an optional WhatsApp number field, shown on all add/edit user and tenant forms. Stored in E.164 format, validated on entry (accept local formats, normalize to `256…`-style international).

## 3. Data Model (custom database tables)

Custom tables were chosen over CPT+meta for relational integrity and reporting performance on financial data.

Prefix: `wp_rm_`

### 3.1 Core tables (v1, with v2 column additions marked)

- **rm_properties**: id, name, address, city, notes, created_at
- **rm_units**: id, property_id (FK), unit_label, bedrooms, rent_amount, status (`vacant` / `occupied` / `maintenance` / **`booked`** — renamed from `reserved`, see §10), **occupancy_type (`single`/`double`/`family`), self_contained (bool), capacity (int, default 1 — max concurrent active leases, >1 for hostel-style per-bed billing)**, notes, created_at
- **rm_tenants**: id, wp_user_id (FK, nullable until portal account created), full_name, phone, email, **whatsapp_number (nullable)**, national_id, **next_of_kin_name (nullable), next_of_kin_phone (nullable), next_of_kin_relationship (nullable)**, status (`active` / `former`), created_at
- **rm_leases**: id, unit_id (FK), tenant_id (FK), start_date, end_date, rent_amount, billing_day, **billing_cycle (`monthly`/`quarterly`/`semester`/`annual`/`custom`), cycle_months (int — resolved months per cycle: 1/3/semester-setting/12/N; the single source of truth for charge math)**, deposit_amount, deposit_status (`unpaid`/`paid`/`refunded`/`forfeited`), status (`active`/`ended`/`renewed`), auto_renewed_from (FK, nullable), created_at
- **rm_charges**: id, lease_id (FK), period_start, period_due_date, amount_due, type (`rent`/`late_fee`/`deposit`), status (`unpaid`/`partial`/`paid`/`waived`), created_at
- **rm_payments**: id, lease_id (FK), charge_id (FK, nullable — unallocated/advance), amount, method (`cash`/`bank_transfer`/`mtn_momo`/`airtel_money`/**`nylonpay`**/`other`), reference_note, **gateway_transaction_id (FK to rm_gateway_transactions, nullable — set only for Nylon Pay payments)**, recorded_by (wp_user_id, nullable — null for webhook-recorded payments), receipt_id (FK), paid_at, created_at
- **rm_receipts**: id, payment_id (FK), receipt_number, pdf_path, emailed_at, **whatsapp_sent_at (nullable)**, created_at
- **rm_documents**: id, entity_type (`lease`/`unit`/`tenant`/**`expense`**), entity_id, attachment_id (WP media ID), label, uploaded_by, created_at
- **rm_property_staff**: property_id, wp_user_id
- **rm_property_landlords**: property_id, wp_user_id
- **rm_notifications_log**: id, type, **channel (`email`/`whatsapp`/`portal`)**, recipient, entity_id, sent_at, status, **failure_reason (nullable)**

### 3.2 New v2 tables

- **rm_unit_amenities**: id, unit_id (FK), tag (varchar) — free-form amenity tags (parking, balcony, water tank…); indexed on tag for filtering. Structured attributes (occupancy_type, self_contained, capacity) live on `rm_units` directly so they're cheaply filterable; tags cover everything else.
- **rm_expenses**: id, scope (`property`/`unit`/`account`), property_id (FK, nullable), unit_id (FK, nullable), category (`water`/`electricity`/`salary`/`tax`/`cleaning`/`custom`), custom_category_label (nullable), amount, expense_date, description, recurring (`none`/`monthly`/`quarterly`/`annual`), recurring_parent_id (FK, nullable — links auto-generated instances to their template), recorded_by (wp_user_id), voided_at (nullable), void_reason (nullable), created_at
- **rm_alerts**: id, title, message, entity_type (`property`/`unit`/`none`), entity_id (nullable), schedule_type (`once`/`daily`/`weekly`/`monthly`), scheduled_at (datetime for `once`; time-of-day + weekday/day-of-month for recurring), recipients (JSON: any of `tenants_of_entity`, `staff_of_entity`, `landlord_of_entity`, `self`, plus explicit wp_user_ids), channels (JSON: `email`/`whatsapp`/`portal`), created_by (wp_user_id), active (bool), last_sent_at (nullable), created_at
- **rm_gateway_transactions**: id, gateway (`nylonpay`), reference (UUID, unique — the idempotency key sent to and returned by Nylon Pay), lease_id (FK), charge_id (FK, nullable), tenant_id (FK), amount, currency, status (`initiated`/`processing`/`successful`/`failed`/`cancelled`/`expired`), initiated_by (`tenant`/`staff`), initiator_user_id, phone_used, raw_webhook_payload (longtext, nullable), created_at, updated_at
- **rm_move_out_notices**: id, lease_id (FK), notice_date, earliest_move_out_date (computed: notice_date + configured notice period), requested_move_out_date (nullable), submitted_by (`tenant`/`staff`), submitted_by_user_id, status (`active`/`completed`/`cancelled`), notes, created_at

### 3.3 Integrity rules

**Soft delete:** `rm_tenants`, `rm_leases`, `rm_units`, `rm_properties` get `deleted_at` (archive + restore, never hard delete). Financial tables (`rm_charges`, `rm_payments`, `rm_receipts`, **`rm_gateway_transactions`**) are never deletable through the UI — corrections via reversing/adjustment entries only. **Expenses follow the same rule: a mistaken expense is voided (`voided_at` + `void_reason`), not deleted, and voided expenses are excluded from reports but visible in an audit view.**

**Capacity invariant (replaces v1's single-active-lease rule):** the number of active leases on a unit must be ≤ `rm_units.capacity`. Default capacity is 1, which reproduces v1 behavior exactly. Enforced at the application layer with a row-lock/transaction guard so two staff can't simultaneously create the (capacity)th and (capacity+1)th lease.

## 4. Module Specs

### 4.1 Tenant & Unit Management

**Data entry flow:** Staff creates a Property, then Units under it. v2 unit fields: label, bedrooms, base rent, status, occupancy type (single/double/family), self-contained toggle, capacity (default 1), amenity tags (autocomplete against existing tags to keep the vocabulary from fragmenting). Tenant records are created independently, then linked via a Lease.

**v2 tenant fields:** WhatsApp number (optional, validated/normalized) and a single optional Next of Kin group (name, phone, relationship). Next of Kin is display-only data — no portal access, no notifications to next of kin.

**Unit status is derived, with manual override:** `occupied` while at least one active lease exists (for capacity > 1 units, show `occupied n/capacity`, e.g. "3/4 beds"); `vacant` when none. Staff can manually set `maintenance` or `booked` (v2 rename of `reserved`), which the system will not auto-clear until staff clears it.

**Hostel/per-bed model:** shared rooms are modeled as one unit with capacity = number of beds, each occupant on their own lease with their own ledger, balance, and portal login. Occupancy reporting counts beds (sum of capacity) vs. filled beds (active leases), not just units — see §4.4.

**Edge cases:**
- Deleting a unit with lease history: blocked; archive instead.
- Exceeding capacity: creating a lease on a unit at capacity is blocked with a clear error naming the conflicting lease(s).
- Reducing capacity below the current active-lease count: blocked until leases are ended.
- Amenity tag filtering: unit list filterable by occupancy type, self-contained, and any tag.
- Search/filter as in v1 (name/phone/unit label; status, property).

**Tradeoff (unchanged from v1, deliberate):** one tenant per lease. Shared occupancy is handled with per-bed leases (capacity > 1), not co-tenants on a single lease — each occupant keeps an independent balance, which is exactly what hostel billing needs. A true co-tenant model (multiple people sharing one balance) remains out of scope.

### 4.2 Lease Tracking, Flexible Billing Cycles & Renewal Reminders

**v2 — Billing cycles:** each lease has a `billing_cycle`: monthly, quarterly, semester, annual, or custom (every N months). "Semester" resolves to a configurable account-wide setting (default 4 months) at lease creation, stored into `cycle_months` so later settings changes don't rewrite existing leases. All charge generation, reminders, and late fees key off `cycle_months` — there is one generalized billing engine, not per-cycle code paths.

**Charge generation:** the daily cron (`rm_generate_charges`, renamed from `rm_generate_monthly_charges`) creates the next period's `rm_charges` row a configurable lead time before the period's due date (default 5 days), for every active lease regardless of cycle. Period due dates advance by `cycle_months` from the lease start / `billing_day` anchor. Month-length edge cases (e.g. billing_day 31 in a 30-day month) clamp to the last day of the target month.

**Renewal reminders:** daily cron scans leases with `end_date` within configurable thresholds. v1 thresholds (30/14/7 days) remain the default but are configurable — semester/annual leases may warrant longer lead times (e.g. 60/30/14), so thresholds are a settings-screen list, not hardcoded. Reminders go to assigned staff, the property's landlord-owner, and optionally the tenant, over email + WhatsApp (per §5) + in-portal banner, deduped via `rm_notifications_log`.

**One-click renewal:** unchanged from v1 — "Renew" duplicates the lease (including cycle fields) for editing, ends the old lease as `renewed`, links via `auto_renewed_from`.

**Edge cases:**
- Expired-not-renewed leases: unit does not auto-vacate; dashboard flags them.
- Cycle change mid-lease: not supported — end the lease and create a new one (keeps the charge ledger unambiguous). The UI should say this explicitly.
- Custom cycle bounds: N is 1–24 months; reject 0/negative/absurd values.
- Timezone: all date math uses the WP site timezone.

### 4.3 Payment Recording, Receipts & Printing

**Manual recording flow:** unchanged from v1 (ledger view → Record Payment → amount/method/date/note; partial payments; overpayment becomes credit auto-applied to next charge; payments against ended leases allowed and flagged).

**v2 — payment methods** now include `nylonpay` (set automatically by the webhook flow, not selectable in the manual form).

**Receipt generation:** on any payment (manual or Nylon Pay webhook), generate a PDF receipt, store it, and deliver it to the tenant via email and WhatsApp per §5. Downloadable from staff lease screen and tenant portal.

**v2 — Receipt printing:** every receipt gets a print-optimized HTML view alongside the PDF:
- An A4/letter-friendly layout for normal desktop printers (browser print dialog).
- A narrow thermal layout (58mm and 80mm variants, selectable in settings) — single column, large type, no backgrounds — for mobile Bluetooth thermal printers. These are driven through the OS/browser print path: on Android, apps like RawBT register Bluetooth ESC/POS printers as system print targets, so the plugin needs no Bluetooth code at all. Document this setup path in the README/help screen.
- Explicit decision: **no Web Bluetooth / direct ESC/POS integration.** It only works in Chrome, pairing UX is poor, and it's a permanent maintenance burden. The print-friendly route covers both printer types with zero device code.

**Late fees:** unchanged from v1 (one-time, non-recurring, configurable grace + flat/% fee; waived fees marked `waived`, never deleted) — but the "period" now follows the lease's billing cycle, so a semester lease gets at most one late fee per semester charge.

### 4.4 Landlord Dashboard, Expenses & Reporting

**v2 — Expense Management:**
- Staff/Admin record expenses (Landlord-Owner cannot — landlords stay read-only on financials). Fields: scope (account / property / unit), category (water, electricity, salary, tax, cleaning, or custom with label), amount, date, description, optional receipt/invoice attachment (via `rm_documents`).
- Recurring expenses: an expense can be marked recurring (monthly/quarterly/annual). A daily cron (`rm_generate_recurring_expenses`) materializes each period's instance from the template row (`recurring_parent_id` linkage). Instances are ordinary expenses — editable or voidable individually without touching the template; editing the template affects only future instances.
- Voiding, not deleting: mistaken expenses are voided with a reason and excluded from reports.
- Expense list screen: filter by property/unit/category/date range/recurring-or-not, CSV export.

**v2 — Expense reports & net statements:**
- New Expense Report: totals by category and by property/unit over a date range, CSV + PDF export, reusing the existing PDF pipeline.
- Landlord statements upgrade from income-only to full P&L shape: income (payments received), expenses (scoped to the statement's property/properties), and **net**. Account-scoped expenses appear only on admin-level reports, never allocated silently to a landlord's statement — allocating shared costs across owners is a business decision the software should not guess at; the statement footnotes that account-level expenses are excluded.

**Dashboard:** as v1 (occupancy, outstanding balance, upcoming expirations, recent payments) plus: monthly expense total, net income figure, and **beds-based occupancy** — occupancy rate = active leases ÷ total capacity (sum of unit capacities), with the unit-count view still available. Landlord-Owner sees the same component scoped to their properties.

**Edge case (unchanged and now more important):** landlord data scoping is enforced at the query layer via `rm_property_landlords` joins — expenses and gateway transactions included.

### 4.5 Tenant Self-Service Portal

Access provisioning unchanged from v1 (staff "Invite to Portal" → WP user + set-password email; invite message also sent via WhatsApp per §5 with the set-password link).

**Portal content:**
- Balance, lease details, payment history, receipt downloads (v1).
- **v2 — Pay Now (Nylon Pay):** on any charge with a balance, tenant taps Pay Now → confirms/edits the phone number to charge (pre-filled from their record) → plugin initiates a Nylon Pay collection → tenant gets the mobile-money prompt on their phone → portal shows a "waiting for confirmation" state and updates when the webhook lands (poll the transaction status endpoint as a fallback if no webhook within ~60s). Success auto-records the payment, generates the receipt, and shows it. Failure/cancel/timeout shows a clear retry path. Amount defaults to the full balance of the selected charge but can be reduced (partial payments allowed, consistent with §4.3); minimum enforced per Nylon Pay (500 UGX).
- **v2 — Give move-out notice:** tenant can submit notice from the portal (creates an `rm_move_out_notices` row, `submitted_by = tenant`). The portal shows the computed earliest move-out date per the notice policy and the rent owed through the notice period. Staff are notified immediately (email + WhatsApp + dashboard flag). Tenants can cancel a pending notice; staff can record or cancel notices on tenants' behalf (walk-ins).

These are the only two write paths in the portal; both require the logged-in tenant to own the lease in question, enforced server-side.

### 4.6 Mobile-Responsive Design

Unchanged from v1: mobile-first portal (single column, card layouts, lightweight), wp-admin screens following WP responsive patterns, three reference breakpoints (~375 / ~768 / ~1280px), no native app. New v2 screens (expenses, alerts, Pay Now flow, notice flow) follow the same rules; the Pay Now waiting state must be designed for a phone held in one hand while the other phone rings with the payment prompt — big status text, no dense UI.

### 4.7 v2 — Communications Layer (Email + WhatsApp)

**Architecture:** a `MessageChannel` interface (`send(recipient, MessageTemplate, context): Result`) with two implementations: `EmailChannel` (wraps `wp_mail`) and `WhatsAppChannel`. WhatsApp ships with a **Meta WhatsApp Cloud API** driver first; the driver layer is pluggable (filter/DI) so Twilio or an aggregator can be added without touching calling code. All existing v1 notification send-sites are refactored to dispatch through this layer instead of calling `wp_mail` directly.

**Sending policy:** every outbound notification goes to email AND WhatsApp whenever the recipient has a WhatsApp number on file; email-only otherwise. No per-user preference in v2 (deliberate simplification — revisit if clients complain about double messages).

**WhatsApp specifics the implementation must respect:**
- Business-initiated WhatsApp messages require **pre-approved message templates** in Meta Business Manager. The plugin ships a documented set of required templates (invite, renewal reminder, payment received/receipt link, overdue notice, custom alert, move-out notice confirmation) with placeholder variables; the settings screen maps each notification type to the account's approved template name. The README documents the Meta Business onboarding steps (business verification, phone number, template approval).
- Receipts: WhatsApp template message with a link to the receipt PDF (media messages can be a fast-follow; links are template-safe and avoid media-upload API complexity).
- Failures (unapproved template, invalid number, API error) are logged to `rm_notifications_log` with `failure_reason`, and the email copy still goes out — WhatsApp failure must never block or delay email.
- Credentials (Cloud API token, phone number ID, business account ID) live in plugin settings, admin-only, never printed in logs.

### 4.8 v2 — Custom Alerts

**What an alert is:** title + message, attached to a property, a unit, or nothing (account-level), with a schedule (one-off datetime, or recurring daily/weekly/monthly at a set time), a recipient selection (tenants of the attached entity, staff assigned to it, its landlord-owner, the creator themselves, and/or explicitly picked users), and channel selection (email / WhatsApp / in-portal banner).

**Who can create them:** Admin and Staff (scoped to their assigned properties); Landlord-Owners on their own properties only (their single write capability). Tenants cannot create alerts.

**Dispatch:** a cron (`rm_dispatch_custom_alerts`, runs every 15 minutes rather than daily, since alerts carry a time-of-day) finds due alerts, resolves the recipient list at send time (current tenants of the unit/property — not a snapshot from creation time), dispatches through the communications layer, stamps `last_sent_at`, and logs per-recipient per-channel results. One-off alerts deactivate after sending; recurring alerts stay active until toggled off or deleted.

**Edge cases:**
- Alert attached to a unit that becomes vacant: tenant-recipient resolution yields nobody; the alert still sends to any staff/landlord recipients and logs the empty tenant set rather than erroring.
- Recipient resolution must respect scoping: a staff-created alert can't address users outside the creator's property scope.
- WhatsApp custom alerts use a generic approved "notification" template with the message as a variable (free-form business-initiated WhatsApp is not allowed by Meta outside a 24h customer-service window).

### 4.9 v2 — Nylon Pay Integration

Nylon Pay (https://docs.nylonpay.nilesquad.com/docs) is a merchant-of-record payments API for Africa: mobile money (MTN MoMo, Airtel Money), cards, and bank transfers behind one SDK, with an official PHP SDK, sandbox mode, signed webhooks, and UUID-reference idempotency.

**Configuration (admin settings):** API key + secret, webhook secret, test/live mode toggle. Sandbox behaves identically to production per their docs, so QA runs entirely in test mode.

**Flows:**
1. **Tenant Pay Now** (portal, §4.5): plugin calls collect-payment with amount, site currency, tenant name/phone, a generated UUID reference, and metadata (`lease_id`, `charge_id`, `site_url`). The reference is stored in `rm_gateway_transactions` (`status = initiated`) before the API call — the DB row exists first, so a crashed request can't produce an untracked payment.
2. **Staff-sent payment request** (lease screen): staff picks a charge and confirms the tenant's phone; the same collection flow fires and the tenant gets the prompt. UI shows request status; `initiated_by = staff`.

**Webhook endpoint:** a REST route (`/wp-json/chrx-rm/v1/nylonpay-webhook`) that: verifies the HMAC signature and replay-protection timestamp using the SDK before any processing; dedupes on the transaction reference (at-least-once delivery — a reference already marked `successful` returns 200 and does nothing); on `collection.completed` marks the gateway transaction `successful`, creates the `rm_payments` row (method `nylonpay`, `recorded_by` null, linked `gateway_transaction_id`), applies partial/overpayment logic identically to manual payments, and triggers the standard receipt pipeline (PDF + email + WhatsApp); on `collection.failed` marks the transaction `failed` and notifies the initiator; returns 200 within the 5-second window by deferring heavy work (PDF generation) to an immediately-scheduled async event.

**Edge cases:**
- Webhook never arrives: a reconciliation cron sweep (part of `rm_generate_charges` daily run or its own job) polls Nylon Pay's status endpoint for `initiated`/`processing` transactions older than 15 minutes and settles them either way; transactions still unresolved after 24h are marked `expired` and surfaced on a staff "needs attention" list.
- Tenant pays the same charge manually (cash) while a gateway request is pending: the webhook payment still records (money genuinely moved); the resulting overpayment becomes credit per §4.3. Staff UI warns when recording a manual payment against a charge with a pending gateway transaction.
- Currency: collections are always in the site currency; if the site currency isn't supported by Nylon Pay, the settings screen refuses to enable the integration rather than failing at payment time.
- Amount below Nylon Pay's minimum (500 UGX): Pay Now button disabled with an explanatory note.
- Secrets storage: keys in the options table, masked in the UI after save, never logged; raw webhook payloads stored in `rm_gateway_transactions.raw_webhook_payload` for audit but with no secrets in them by design.

### 4.10 v2 — Move-Out Notice Policy

**Policy setting:** account-wide configurable notice period in months (default 2), settable per property as an override (hostels may differ from apartments).

**Flow:** a notice (from tenant via portal, or entered by staff) creates an `rm_move_out_notices` row with `earliest_move_out_date = notice_date + notice period`. The lease detail screen and dashboard show active notices; the final move-out is executed through the existing v1 move-out workflow (§4.2/v1), which now pre-fills from the notice and computes rent owed through the notice period — if the tenant leaves before `earliest_move_out_date`, the final balance includes rent up to that date (this is the policy's financial teeth; staff can waive the difference explicitly, logged as an adjustment).

**Edge cases:**
- Notice on a lease that expires before the notice period elapses: earliest move-out date caps at lease end — a notice never extends liability beyond the lease term.
- Cancelled notices keep their row (`status = cancelled`) for history.
- Multiple active notices per lease: blocked; cancel the existing one first.

## 5. Notifications Summary

All rows below dispatch through the communications layer (§4.7): email always; WhatsApp additionally whenever the recipient has a WhatsApp number; in-portal/dashboard banners where marked. All sends logged per-channel in `rm_notifications_log`.

| Event | Recipients | Channels |
|---|---|---|
| Lease expiring (configurable thresholds) | Staff, landlord-owner, tenant (optional) | Email + WhatsApp + banner |
| Payment recorded (manual or Nylon Pay) | Assigned staff | Email + WhatsApp |
| Receipt generated | Tenant | Email (PDF attached) + WhatsApp (receipt link) |
| Charge overdue past grace | Assigned staff | Email + WhatsApp |
| Portal invite | Tenant | Email (set-password link) + WhatsApp (link) |
| Nylon Pay payment failed | Initiator (tenant or staff) | Email + WhatsApp + portal state |
| Move-out notice submitted/cancelled | Assigned staff, landlord-owner | Email + WhatsApp + dashboard flag |
| Custom alert due | Per-alert recipient config | Per-alert channel config |

## 6. Scheduled Jobs (WP-Cron)

Standard WP-Cron remains sufficient for the target scale; README documents real-cron setup for low-traffic sites. Jobs:

- `rm_generate_charges` (daily; renamed from `rm_generate_monthly_charges`): cycle-aware charge creation for all active leases
- `rm_send_renewal_reminders` (daily): configurable thresholds, deduped
- `rm_apply_late_fees` (daily): one-time fee per overdue charge past grace
- **`rm_generate_recurring_expenses` (daily, v2):** materialize recurring expense instances
- **`rm_dispatch_custom_alerts` (every 15 min, v2):** send due custom alerts
- **`rm_reconcile_gateway_transactions` (hourly, v2):** poll unresolved Nylon Pay transactions, settle or expire

## 7. Non-Functional Requirements

- **Data integrity over convenience:** financial rows (`rm_charges`, `rm_payments`, `rm_receipts`, `rm_gateway_transactions`) are append-only; expenses void rather than delete; corrections via adjustment entries.
- **Server-side authorization:** every query scoped by role at the query layer — including the new expense, alert, and gateway-transaction queries, and both portal write paths (Pay Now, move-out notice).
- **Webhook security:** Nylon Pay webhook requires valid HMAC signature + freshness check before any processing; handler is idempotent by transaction reference.
- **Secrets:** gateway and WhatsApp credentials admin-only, masked after save, never logged.
- **File storage:** documents, receipts, statements via WP media library / uploads directory.
- **Localization-ready:** all UI strings i18n-wrapped, including new v2 screens and WhatsApp template documentation.
- **Timezone:** all date/time logic uses the WP site timezone.

## 8. Known Limitations (documented, not silently omitted)

- One tenant per lease — shared rooms are per-bed leases via unit capacity, not co-tenants sharing one balance
- Single currency per site; Nylon Pay only works where the site currency is Nylon Pay-supported
- WhatsApp is outbound-only; replies are not received. Templates must be pre-approved in Meta Business Manager before WhatsApp sending works — a real onboarding step for each client
- No per-user channel preferences — WhatsApp-and-email is all-or-nothing per recipient (based on whether a number is on file)
- Bluetooth printing relies on OS-level printer registration (e.g. RawBT on Android); the plugin does not talk to printers directly
- No maintenance ticketing; no license gating; single company per install

## 9. Open Items for Future Rounds

- Per-user notification channel preferences if double messaging generates complaints
- WhatsApp media messages (send receipt PDF natively rather than a link)
- Additional gateway drivers behind the same abstraction (Flutterwave, Pesapal)
- Expense allocation rules for splitting account-level costs across landlord statements
- True co-tenant (shared-balance) leases if demand appears — still a breaking schema change

## 10. v1 → v2 Migration Notes (implementer checklist)

Schema/data migrations required on upgrade, in order:

1. `rm_units`: rename status value `reserved` → `booked` (enum/CHECK update + `UPDATE ... SET status='booked' WHERE status='reserved'`); add `occupancy_type` (default `single`), `self_contained` (default 0), `capacity` (default 1).
2. `rm_tenants`: add `whatsapp_number`, `next_of_kin_name`, `next_of_kin_phone`, `next_of_kin_relationship` (all nullable).
3. `rm_leases`: add `billing_cycle` (default `monthly`) and `cycle_months` (default 1) — existing leases become explicit monthly leases, behavior unchanged.
4. `rm_payments`: add `nylonpay` to method set; add nullable `gateway_transaction_id`; make `recorded_by` nullable.
5. `rm_receipts`: add `whatsapp_sent_at`.
6. `rm_documents`: add `expense` to entity_type set.
7. `rm_notifications_log`: add `channel` (backfill existing rows as `email`) and `failure_reason`.
8. Create new tables: `rm_unit_amenities`, `rm_expenses`, `rm_alerts`, `rm_gateway_transactions`, `rm_move_out_notices`.
9. Replace the single-active-lease guard with the capacity guard (§3.3) — with all capacities defaulting to 1, no behavior changes until a capacity is raised.
10. Rename cron hook `rm_generate_monthly_charges` → `rm_generate_charges` (unschedule old, schedule new on upgrade); register the three new jobs.
11. UI: every occurrence of "Reserved" becomes "Booked" (labels, filters, badges, designs).
12. Refactor all direct `wp_mail` call sites through the new communications layer before adding WhatsApp — one dispatch path, then two channels.

All migrations must be idempotent (safe to run twice) and gated on the stored schema version option.
