# Claude Code Build Prompts — Chrx Rental Manager v2 (client-feedback features)

How to use this file: the v1 plugin is already built in this folder. Run these phases in order, one at a time, pasting each into Claude Code as-is. Wait for a phase to complete and review before starting the next; each phase assumes all prior phases (v1 Phases 0–8 and earlier v2 phases) exist and pass their tests.

Source of truth: `SPEC.md` **Version 2.0** in this folder. Section 10 of SPEC.md is the migration checklist this build must satisfy. Section references below (§) point at SPEC.md.

## Global conventions (apply to every phase)

- This is an UPGRADE of a working v1 codebase, not a rebuild. Read the existing code in `src/`, `templates/`, and `tests/` relevant to each phase before writing anything; extend existing patterns (repository base class, access helper, PDF pipeline, cron class structure, template conventions) rather than introducing parallel ones.
- All conventions from the v1 prompts remain in force: PSR-4 under `ChrxRentalManager\` → `src/`, Composer with committed `vendor/`, PHP 8.0+/WP 6.0+, phpcs passing, nonces + server-side capability checks on every state-changing action, `$wpdb->prepare()` everywhere, output escaping in all templates, i18n-wrapped strings, WP site timezone for all date math.
- Every schema change goes through the existing migration runner, gated on the stored schema version option, and must be idempotent (safe to run twice). Never edit an already-shipped migration — add new ones.
- v1 sites upgrading in place must keep working: defaults in §10 (capacity 1, billing_cycle monthly, cycle_months 1, channel backfill 'email') exist precisely so upgraded data behaves identically until someone uses a new feature. Test this explicitly where a phase touches existing behavior.
- **Designs:** no new HTML mockups exist for v2 screens. Build new screens (expenses, alerts, Pay Now flow, move-out notice, print layouts, new settings tabs) by reusing the established v1 design language — same components, badge system, table/card patterns, empty states, and breakpoints as the existing screens in `templates/` and `designs/`. Where a v2 feature modifies an existing screen (unit form, lease form, tenant form, dashboard, portal), extend that screen's existing layout. If a layout decision feels genuinely ambiguous, ask rather than invent a new visual pattern.
- If anything in SPEC.md is ambiguous or contradicts the existing code, stop and ask before implementing — especially for money and date logic.
- End every phase with: what was built, deviations from SPEC.md and why, anything deferred.

---

## Phase V2-0 — Migrations, schema, and invariant changes

Read SPEC.md §3 (full data model) and §10 (migration checklist) before writing code, plus the existing migration runner and `src/Data/` classes.

Build, as idempotent migrations plus model updates:
- §10 items 1–8: all column additions (`rm_units` occupancy_type/self_contained/capacity + `reserved`→`booked` data migration; `rm_tenants` whatsapp_number + next-of-kin fields; `rm_leases` billing_cycle/cycle_months; `rm_payments` nylonpay method + nullable gateway_transaction_id + nullable recorded_by; `rm_receipts` whatsapp_sent_at; `rm_documents` expense entity type; `rm_notifications_log` channel with 'email' backfill + failure_reason) and all five new tables (`rm_unit_amenities`, `rm_expenses`, `rm_alerts`, `rm_gateway_transactions`, `rm_move_out_notices`) with appropriate indexes.
- §10 item 9: replace the single-active-lease guard with the capacity guard from §3.3 (active leases per unit ≤ `rm_units.capacity`, transaction/row-lock safe against concurrent lease creation). With capacity defaulting to 1 this must be behaviorally identical to v1 — prove it by keeping the existing v1 double-lease test passing unchanged, then add tests for capacity > 1 (allows up to N, blocks N+1, blocks reducing capacity below active count).
- §10 item 10: rename cron hook `rm_generate_monthly_charges` → `rm_generate_charges` (unschedule old, schedule new during the upgrade migration). Register — but leave as no-op stubs for now — the three new jobs: `rm_generate_recurring_expenses` (daily), `rm_dispatch_custom_alerts` (15-minute interval; add the custom cron schedule), `rm_reconcile_gateway_transactions` (hourly). Later phases fill them in.
- New `src/Data/` repository classes for the five new tables, following the existing base-repository pattern, with the audit rules from §3.3 (gateway transactions append-only; expenses void-not-delete).
- PHPUnit tests: migration idempotency (run twice, same result), `reserved`→`booked` data conversion, capacity guard suite, and a simulated v1→v2 upgrade on seeded v1 data asserting nothing about existing leases/charges/payments changes.

Deliverable: a seeded v1 database upgrades cleanly to the v2 schema with zero behavioral change, all v1 tests still green, new tables and guards in place.

---

## Phase V2-1 — Communications layer (email + WhatsApp)

Read SPEC.md §4.7, §5, and §10 item 12 before proceeding, plus every existing `wp_mail` call site in the codebase (grep for them and list them first).

Build:
- The `MessageChannel` interface and `EmailChannel` implementation, then refactor ALL existing v1 notification send-sites (renewal reminders, payment recorded, overdue, portal invite, receipt email) to dispatch through a central notifier service instead of calling `wp_mail` directly. This refactor lands and passes tests BEFORE any WhatsApp code is written — one dispatch path, then two channels (§10 item 12).
- `WhatsAppChannel` with a pluggable driver layer (filter/DI so other providers can be added), shipping a Meta WhatsApp Cloud API driver: credentials (token, phone number ID, business account ID) in a new admin-only settings tab, masked after save, never logged.
- Template mapping per §4.7: a settings UI mapping each notification type (invite, renewal reminder, payment received/receipt link, overdue notice, custom alert, move-out notice confirmation) to the account's approved Meta template name, with documented placeholder variables. Add a README section documenting the Meta Business onboarding steps and the required template set.
- Sending policy per §4.7: every notification → email always; WhatsApp additionally iff the recipient has a WhatsApp number on file. WhatsApp failure (unapproved template, invalid number, API error) logs to `rm_notifications_log` with `channel='whatsapp'` and `failure_reason`, and must never block or delay the email send.
- Per-channel logging in `rm_notifications_log` for every send.
- WhatsApp number fields in the UI: tenant add/edit form (`rm_tenants.whatsapp_number`) and staff/landlord user profile fields (user meta), with E.164 validation/normalization per §2.
- Tests: notifier fan-out logic (email-only vs both), failure isolation (WhatsApp throws → email still sends, failure logged), number normalization. Mock the Cloud API — no live calls in tests.

Deliverable: all v1 notifications flow through the new layer unchanged for email-only recipients; recipients with WhatsApp numbers get both; failures are logged and non-blocking.

---

## Phase V2-2 — Units & tenants: capacity, amenities, booked, next of kin

Read SPEC.md §4.1 and §10 item 11 before proceeding, plus the existing unit/tenant form and list templates.

Build:
- Unit add/edit form additions: occupancy type (single/double/family), self-contained toggle, capacity (default 1, validated ≥1), amenity tags with autocomplete against existing `rm_unit_amenities` tags.
- Unit list/detail updates: show new fields; filter by occupancy type, self-contained, and amenity tag; for capacity > 1 units show occupancy as `n/capacity` (e.g. "3/4 beds") per §4.1.
- §10 item 11: every UI occurrence of "Reserved" becomes "Booked" — labels, filters, status badges, and any seed data. The status badge system stays otherwise unchanged.
- Tenant add/edit form: optional Next of Kin group (name, phone, relationship) — display-only data per §4.1, shown on the tenant detail screen.
- Beds-based occupancy per §4.4: dashboard and occupancy report gain the beds view (active leases ÷ sum of capacity) alongside the existing unit-count view.
- Tests: capacity edit validation (cannot set below active-lease count — guard from V2-0 surfaced properly in the form), amenity tag filtering query.

Deliverable: hostel-style units are fully manageable (capacity, per-bed occupancy display), amenities filterable, Booked rename complete everywhere, next of kin captured.

---

## Phase V2-3 — Flexible billing cycles

Read SPEC.md §4.2 in full before proceeding, plus the existing charge-generation cron class, late-fee cron, reminder cron, and lease form/tests from v1.

Build:
- Lease add/edit/renew forms: billing cycle selector (monthly / quarterly / semester / annual / custom every-N-months, N validated 1–24). Semester resolves from the account setting (default 4 months, new field on the settings screen) into `cycle_months` at lease creation per §4.2 — later settings changes must not rewrite existing leases. Renewal copies cycle fields.
- Generalize the charge engine in `rm_generate_charges` to advance period due dates by `cycle_months` from the lease anchor for every cycle, with month-end clamping (billing_day 31 in a 30-day month → last day) per §4.2. Existing monthly leases (cycle_months = 1) must produce byte-identical charge rows to v1 logic — lock this with a regression test against the v1 fixtures.
- Late fees become cycle-aware: at most one late fee per charge period regardless of cycle length (§4.3).
- Renewal reminder thresholds become a configurable list on the settings screen (default 30/14/7) per §4.2, consumed by the reminder cron.
- Mid-lease cycle changes are not supported: the cycle selector is locked on existing leases with explanatory copy ("end this lease and create a new one to change billing cycle") per §4.2.
- Tests: heavy coverage here per SPEC.md's own risk framing — cycle math for each preset and custom N, month-end clamping, semester setting resolution timing, one-late-fee-per-period across cycles, timezone boundary cases, and the monthly-regression fixture test.

Deliverable: semester/quarterly/annual/custom leases bill, remind, and late-fee correctly; existing monthly leases are provably unaffected.

---

## Phase V2-4 — Expense management & net reporting

Read SPEC.md §4.4 (expenses, reports, dashboard) before proceeding, plus the existing reports screen, statement generator, PDF pipeline, and CSV export helpers.

Build:
- Expenses admin screen (new menu item): list with filters (property/unit/category/date range/recurring/voided), CSV export, add/edit form with scope (account/property/unit), category presets + custom label, amount, date, description, optional attachment via `rm_documents` (entity_type `expense`). Staff scoped to assigned properties via the access helper; Landlord-Owner has no access to expense entry (§4.4).
- Void-not-delete: void action requires a reason, voided rows excluded from all reports/totals but visible under a "Voided" filter (§3.3).
- Recurring expenses: recurring flag (monthly/quarterly/annual) on the form; implement the `rm_generate_recurring_expenses` cron (stubbed in V2-0) to materialize instances with `recurring_parent_id` linkage — instances independently editable/voidable; template edits affect only future instances (§4.4). Dedupe so a period is never materialized twice.
- Expense Report on the reports screen: totals by category and by property/unit over a date range, CSV + PDF export reusing the existing PDF pipeline.
- Landlord statement upgrade to P&L per §4.4: income, expenses (statement-scope only), net. Account-scoped expenses excluded from landlord statements with the footnote SPEC.md specifies; they appear on admin-level reports only.
- Dashboard additions: monthly expense total and net income figure (role-scoped like everything else on the dashboard).
- Tests: recurring materialization (including dedupe on double cron run), void exclusion from totals, landlord-statement scoping (account-level expenses never leak into an owner's statement — query-layer test in the style of the v1 cross-owner test).

Deliverable: expenses recordable/recurring/voidable, expense reports export, landlord statements show true net, dashboards show expense and net figures.

---

## Phase V2-5 — Nylon Pay integration

Read SPEC.md §4.9 in full plus §4.5 (Pay Now UX) and §7 (webhook security) before proceeding. Also read Nylon Pay's docs at https://docs.nylonpay.nilesquad.com/docs — specifically SDK configuration, collect-payment, get-status, and the webhooks guide — and add their official PHP SDK via Composer (committed vendor/ per project convention).

Build:
- Settings tab: API key/secret, webhook secret, test/live mode toggle; keys masked after save, never logged; integration refuses to enable if the site currency isn't Nylon Pay-supported (§4.9).
- `rm_gateway_transactions` write-first flow per §4.9: DB row (`status=initiated`, UUID reference generated locally) is persisted BEFORE the collect-payment API call, with metadata (lease_id, charge_id, site_url).
- Tenant Pay Now in the portal per §4.5: charge selector → confirm/edit phone (pre-filled) → initiate collection → "waiting for confirmation" state (poll transaction status as fallback if no webhook within ~60s) → success shows the receipt; failure/cancel/timeout shows a retry path. Amount defaults to full charge balance, reducible (partials allowed), minimum 500 UGX enforced with the Pay Now button disabled + note below minimum. Mobile-first per §4.6's note (big status text on the waiting screen).
- Staff-sent payment request from the lease screen (`initiated_by=staff`) per §4.9, with visible request status.
- Webhook REST route `/wp-json/chrx-rm/v1/nylonpay-webhook` per §4.9, in this exact order: verify HMAC signature + replay-protection freshness via the SDK before ANY processing → dedupe on reference (already-successful reference → return 200, do nothing) → on `collection.completed`: mark transaction successful, create the `rm_payments` row (method `nylonpay`, `recorded_by` NULL, linked `gateway_transaction_id`), run the SAME partial/overpayment allocation code path as manual payments (§4.3 — do not fork the logic), trigger the standard receipt pipeline → on `collection.failed`: mark failed, notify the initiator through the communications layer. Return 200 within 5 seconds by deferring PDF generation to an immediately-scheduled async event.
- Implement the `rm_reconcile_gateway_transactions` hourly cron (stubbed in V2-0): poll status for `initiated`/`processing` transactions older than 15 minutes, settle them; mark >24h unresolved as `expired` and surface them on a staff "needs attention" list (§4.9).
- Manual-payment collision warning per §4.9: recording a manual payment against a charge with a pending gateway transaction shows a warning; a webhook payment landing after a manual one flows into the normal overpayment-credit logic.
- Tests (mock the SDK/HTTP — sandbox is for manual QA, not the test suite): signature rejection, replay rejection, webhook idempotency on duplicate delivery, successful-collection → payment + receipt creation, failed collection notification, reconciliation sweep transitions, below-minimum guard. Manual QA checklist for sandbox mode included in the phase report.

Deliverable: tenants can pay from the portal and staff can request payments; webhooks are verified, idempotent, and auto-record payments through the existing ledger logic; stuck transactions self-reconcile or surface for attention.

---

## Phase V2-6 — Custom alerts

Read SPEC.md §4.8 before proceeding, plus the communications layer from V2-1 and the access helper.

Build:
- Alerts admin screen (list + add/edit): title, message, attach to property/unit/none, schedule (one-off datetime, or recurring daily/weekly/monthly at a time-of-day), recipient selection (tenants of entity / staff of entity / landlord of entity / self / explicit users), channel selection (email/WhatsApp/portal banner), active toggle.
- Permissions per §4.8 and §2: Admin everywhere; Staff scoped to assigned properties; Landlord-Owner gets the new `rm_manage_own_alerts` capability for alerts on their own properties ONLY — this is their single write path, so cover it with an explicit cross-owner denial test. Recipient resolution must not let a creator address users outside their property scope. Tenants have no alert access.
- Implement the `rm_dispatch_custom_alerts` 15-minute cron (stubbed in V2-0): find due alerts, resolve recipients AT SEND TIME (current tenants of the entity, not a creation-time snapshot), dispatch through the communications layer (WhatsApp via the generic approved notification template with the message as a variable, per §4.8), stamp `last_sent_at`, log per-recipient per-channel, deactivate one-off alerts after sending.
- Portal/dashboard banner channel: due alerts with the portal channel render as banners for their resolved recipients.
- Edge cases per §4.8: alert on a now-vacant unit sends to remaining staff/landlord recipients and logs the empty tenant set without erroring; recurring alerts keep firing until deactivated.
- Tests: schedule due-ness math (one-off + each recurrence), send-time recipient resolution, scope enforcement (staff and landlord), one-off deactivation, dedupe (a due alert isn't sent twice by overlapping cron runs).

Deliverable: staff and landlords can create scoped, scheduled, multi-channel alerts that dispatch reliably.

---

## Phase V2-7 — Move-out notice policy & portal notice flow

Read SPEC.md §4.10 and §4.5 (give-notice UX) before proceeding, plus the existing v1 move-out workflow and settings screen.

Build:
- Settings: account-wide notice period in months (default 2) with per-property override (§4.10).
- Notice creation from both sides: tenant portal "Give notice" flow (creates `rm_move_out_notices`, `submitted_by=tenant`, server-side lease-ownership check — this is the portal's second write path) and staff entry from the lease screen (`submitted_by=staff`, for walk-ins). Both compute and display `earliest_move_out_date = notice_date + applicable notice period`, capped at lease end per §4.10.
- Notifications on submit/cancel to assigned staff and landlord-owner via the communications layer, plus a dashboard flag; the portal shows the tenant their active notice, earliest move-out date, and rent owed through the notice period.
- Only one active notice per lease (block with clear error); tenants can cancel their pending notice; cancelled notices retained with `status=cancelled`.
- Wire into the existing v1 move-out workflow: executing a move-out on a lease with an active notice pre-fills from it and computes the final balance including rent through `earliest_move_out_date` if the tenant leaves early — with an explicit, logged staff waiver action for the difference (adjustment entry per §7, never a silent edit). Completing move-out marks the notice `completed`.
- Tests: earliest-date math (including the lease-end cap), single-active-notice constraint, tenant ownership enforcement on the portal path, final-balance-through-notice-period calculation, waiver adjustment logging.

Deliverable: notice policy is configurable and enforced, tenants can give/cancel notice from the portal, move-outs settle correctly against the notice period.

---

## Phase V2-8 — Receipt printing (desktop + Bluetooth thermal)

Read SPEC.md §4.3 (printing) before proceeding, plus the existing receipt template/PDF pipeline.

Build:
- Print-optimized HTML receipt views alongside the existing PDF: an A4/letter layout for desktop printers, and narrow thermal layouts in 58mm and 80mm variants (single column, large type, no background colors/images, minimal margins) selectable via a settings option per §4.3.
- "Print" actions on the staff receipt/payment screens and the tenant portal receipt view, opening the print-friendly view and invoking the browser print dialog. Print CSS via `@media print` with the thermal width set from the chosen variant.
- No Web Bluetooth, no ESC/POS code, no device integration — §4.3 is explicit. Add a help-screen/README section documenting the Android Bluetooth printing path (RawBT-style apps registering the printer as a system print target) and standard desktop printing.
- Verify thermal output renders correctly at 58mm and 80mm widths (test prints via browser print-preview at those page widths; include screenshots or a rendering checklist in the phase report).

Deliverable: any receipt printable from desktop and mobile through the normal print dialog, with clean thermal-width layouts and documented printer setup.

---

## Phase V2-9 — v2 regression, security, and upgrade QA

This phase is verification, not new features. All V2-0…V2-8 work must be merged first.

Do:
- Full upgrade test: take a seeded v1 database, run the v2 upgrade, and verify §10's checklist item by item — statuses renamed, defaults applied, cron hooks swapped, no changes to existing charges/payments/receipts, all migrations idempotent on a second run.
- Run the entire PHPUnit suite (v1 + v2) and phpcs; fix failures.
- Re-verify the security invariants across all NEW surfaces: the two portal write paths (Pay Now, give notice) enforce lease ownership server-side; the webhook verifies signature + freshness before processing and is idempotent; expenses/alerts/gateway-transactions/notices queries all go through the access helper; landlord write access is limited to exactly `rm_manage_own_alerts` on own properties; secrets are masked and absent from logs.
- Walk every modified v1 screen (unit/tenant/lease forms, dashboard, reports, statements, portal) and every new v2 screen against the v1 design language for consistency — no new visual patterns, badge system intact including the Booked rename everywhere (grep the codebase and templates for any residual "reserved"/"Reserved").
- Verify responsive behavior of all new screens at ~375/~768/~1280px, with particular attention to the Pay Now waiting state on mobile.
- Confirm SPEC.md §8 limitations hold (outbound-only WhatsApp, no per-user channel prefs, no direct printer integration, one tenant per lease, single currency) — nothing over- or under-built.
- Produce a final report: features delivered per SPEC.md module, test coverage summary, migration verification results, deviations and why, deferred items, and the manual QA steps that still need a human (Meta template approval walkthrough, Nylon Pay sandbox end-to-end, physical thermal printer test).

Deliverable: v2 complete and verified — an upgraded v1 site runs unchanged until the new features are switched on, and every new feature matches SPEC.md v2.
