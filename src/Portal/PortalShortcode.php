<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Portal;

use ChrxRentalManager\Admin\Support\Ledger;
use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Receipt;
use ChrxRentalManager\Data\Unit;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[rental_portal]` (SPEC.md §4.5, designs/29-33) — the tenant's entire
 * self-service portal, view-only, delivered as one shortcode so a site
 * owner can drop it on any page in any active theme (SPEC.md §4.5's
 * "front-end implementation" note). Sub-views are query-string driven
 * on the same page rather than separate WP pages/rewrite rules, since a
 * single `[rental_portal]` shortcode is the whole deliverable.
 *
 * Every query resolves the tenant/lease strictly from the logged-in WP
 * user id via PortalContext — nothing in this class ever trusts a lease
 * id, tenant id, or receipt id taken from the request to select *whose*
 * data to show, only to select *which of this tenant's own rows* to show
 * (PortalContext::lease_belongs_to_tenant() is the ownership guard).
 */
final class PortalShortcode {

	public const VIEW_HOME     = 'home';
	public const VIEW_LEASE    = 'lease';
	public const VIEW_PAYMENTS = 'payments';
	public const VIEW_RECEIPT  = 'receipt';

	private PortalContext $context;
	private Unit $units;
	private Property $properties;
	private Charge $charges;
	private Payment $payments;
	private Receipt $receipts;
	private Ledger $ledger;

	public function __construct(
		?PortalContext $context = null,
		?Unit $units = null,
		?Property $properties = null,
		?Charge $charges = null,
		?Payment $payments = null,
		?Receipt $receipts = null,
		?Ledger $ledger = null
	) {
		$this->context    = $context ?? new PortalContext();
		$this->units      = $units ?? new Unit();
		$this->properties = $properties ?? new Property();
		$this->charges    = $charges ?? new Charge();
		$this->payments   = $payments ?? new Payment();
		$this->receipts   = $receipts ?? new Receipt();
		$this->ledger     = $ledger ?? new Ledger( $this->charges, $this->payments );
	}

	public function register(): void {
		add_shortcode( 'rental_portal', array( $this, 'render' ) );
	}

	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_partial( 'templates/portal/logged-out.php', array() );
		}

		if ( ! current_user_can( RoleManager::CAP_VIEW_PORTAL ) ) {
			return $this->render_partial( 'templates/portal/not-a-tenant.php', array() );
		}

		$wp_user_id = get_current_user_id();
		$tenant     = $this->context->tenant_for_wp_user( $wp_user_id );

		if ( null === $tenant ) {
			return $this->render_partial( 'templates/portal/not-a-tenant.php', array() );
		}

		$tenant_id = (int) $tenant['id'];
		$leases    = $this->context->leases_for_tenant( $tenant_id );
		$lease     = $this->context->active_lease( $leases );

		if ( null === $lease ) {
			return $this->render_no_active_lease( $tenant, $leases );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param, no state change.
		$view = isset( $_GET['rm_view'] ) ? sanitize_key( wp_unslash( $_GET['rm_view'] ) ) : self::VIEW_HOME;

		switch ( $view ) {
			case self::VIEW_LEASE:
				return $this->render_lease_details( $tenant, $lease );

			case self::VIEW_PAYMENTS:
				return $this->render_payment_history( $tenant, $tenant_id, $leases );

			case self::VIEW_RECEIPT:
				return $this->render_receipt_detail( $tenant, $tenant_id );

			default:
				return $this->render_home( $tenant, $lease );
		}
	}

	/**
	 * @param array<string,mixed> $tenant
	 * @param array<string,mixed> $lease
	 */
	private function render_home( array $tenant, array $lease ): string {
		$unit     = $this->units->find( (int) $lease['unit_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		$open_charges = $this->charges->unpaid_or_partial_for_lease( (int) $lease['id'] );
		$balance      = $this->ledger->outstanding_balance_for_lease( (int) $lease['id'] );
		$today        = current_time( 'Y-m-d' );

		$is_overdue     = false;
		$late_fee_total = 0.0;

		foreach ( $open_charges as $charge ) {
			if ( $charge['period_due_date'] < $today ) {
				$is_overdue = true;
			}

			if ( Charge::TYPE_LATE_FEE === $charge['type'] ) {
				$late_fee_total += $this->ledger->outstanding_for_charge( $charge );
			}
		}

		// "Next due" — the open charge with the furthest-out due date,
		// approximating "what's coming up" whether that's the only open
		// charge or the newest one alongside older overdue ones; this
		// plugin's billing cron only ever keeps one upcoming rent charge
		// generated ahead of time (SPEC.md §4.2), so in the common case
		// there's exactly one candidate.
		$next_due_charge = null;

		foreach ( $open_charges as $charge ) {
			if ( null === $next_due_charge || $charge['period_due_date'] > $next_due_charge['period_due_date'] ) {
				$next_due_charge = $charge;
			}
		}

		return $this->render_partial(
			'templates/portal/home.php',
			array(
				'tenant'          => $tenant,
				'lease'           => $lease,
				'unit'            => $unit,
				'property'        => $property,
				'balance'         => $balance,
				'is_overdue'      => $is_overdue,
				'late_fee_total'  => $late_fee_total,
				'next_due_charge' => $next_due_charge,
			)
		);
	}

	/**
	 * @param array<string,mixed> $tenant
	 * @param array<string,mixed> $lease
	 */
	private function render_lease_details( array $tenant, array $lease ): string {
		$unit     = $this->units->find( (int) $lease['unit_id'] );
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		return $this->render_partial(
			'templates/portal/lease-details.php',
			array(
				'tenant'   => $tenant,
				'lease'    => $lease,
				'unit'     => $unit,
				'property' => $property,
			)
		);
	}

	/**
	 * @param array<string,mixed>            $tenant
	 * @param array<int,array<string,mixed>> $leases
	 */
	private function render_payment_history( array $tenant, int $tenant_id, array $leases ): string {
		$rows = array();

		// Across every lease this tenant has ever had, not just the
		// current one — a renewal must not make earlier payment history
		// disappear from the tenant's own view of their history.
		foreach ( $leases as $lease ) {
			foreach ( $this->payments->for_lease( (int) $lease['id'] ) as $payment ) {
				$charge = null !== $payment['charge_id'] ? $this->charges->find( (int) $payment['charge_id'] ) : null;

				$rows[] = array(
					'payment' => $payment,
					'charge'  => $charge,
				);
			}
		}

		usort( $rows, static fn( array $a, array $b ): int => strtotime( $b['payment']['paid_at'] ) <=> strtotime( $a['payment']['paid_at'] ) );

		return $this->render_partial(
			'templates/portal/payment-history.php',
			array(
				'tenant' => $tenant,
				'rows'   => $rows,
			)
		);
	}

	/**
	 * @param array<string,mixed> $tenant
	 */
	private function render_receipt_detail( array $tenant, int $tenant_id ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param; ownership is verified below before any data is shown, not trusted from the request.
		$receipt_id = isset( $_GET['rm_receipt_id'] ) ? absint( $_GET['rm_receipt_id'] ) : 0;
		$receipt    = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			return $this->render_partial( 'templates/portal/receipt-not-found.php', array( 'tenant' => $tenant ) );
		}

		$payment = $this->payments->find( (int) $receipt['payment_id'] );

		// The ownership guard: a receipt/payment whose lease isn't one of
		// this tenant's own leases is treated exactly like "not found" —
		// this is what stops the ID-manipulation attack (SPEC.md §4.5's
		// test requirement), not a hidden link in the UI.
		if ( null === $payment || ! $this->context->lease_belongs_to_tenant( (int) $payment['lease_id'], $tenant_id ) ) {
			return $this->render_partial( 'templates/portal/receipt-not-found.php', array( 'tenant' => $tenant ) );
		}

		$charge   = null !== $payment['charge_id'] ? $this->charges->find( (int) $payment['charge_id'] ) : null;
		$lease    = ( new \ChrxRentalManager\Data\Lease() )->find( (int) $payment['lease_id'] );
		$unit     = null !== $lease ? $this->units->find( (int) $lease['unit_id'] ) : null;
		$property = null !== $unit ? $this->properties->find( (int) $unit['property_id'] ) : null;

		return $this->render_partial(
			'templates/portal/receipt-detail.php',
			array(
				'tenant'   => $tenant,
				'receipt'  => $receipt,
				'payment'  => $payment,
				'charge'   => $charge,
				'unit'     => $unit,
				'property' => $property,
			)
		);
	}

	/**
	 * @param array<string,mixed>            $tenant
	 * @param array<int,array<string,mixed>> $leases
	 */
	private function render_no_active_lease( array $tenant, array $leases ): string {
		return $this->render_partial(
			'templates/portal/no-active-lease.php',
			array(
				'tenant'            => $tenant,
				'has_lease_history' => array() !== $leases,
				'upcoming_lease'    => $this->context->next_upcoming_lease( $leases ),
				'units'             => $this->units,
				'properties'        => $this->properties,
			)
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function render_partial( string $relative_path, array $data ): string {
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- same include-with-scope pattern as Billing\ReceiptPdf::receipt_html()/StatementPdf::statement_html(), just for front-end templates instead of PDF templates.

		ob_start();
		include \ChrxRentalManager\PLUGIN_DIR . '/' . $relative_path;

		return (string) ob_get_clean();
	}
}
