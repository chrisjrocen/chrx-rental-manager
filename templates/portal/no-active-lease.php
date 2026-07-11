<?php
/**
 * "No active lease yet" empty state (designs/33-no-active-lease.html) —
 * shown both to a tenant invited before move-in and to a former tenant
 * with no currently active lease (SPEC.md §4.5: "a tenant with no lease
 * yet can still be invited... the portal shows 'no active lease' state
 * gracefully rather than erroring").
 *
 * Variables in scope: $tenant (array), $has_lease_history (bool),
 * $upcoming_lease (?array), $units (Data\Unit), $properties (Data\Property).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_name = '';
$active        = '';
?>
<div class="chrx-rm-portal">
	<div class="chrx-rm-portal__mobile-header">
		<div class="chrx-rm-portal__mobile-header-top" style="justify-content:space-between;margin-bottom:16px;">
			<span></span>
			<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</div>
		<div class="chrx-rm-portal__welcome"><?php esc_html_e( 'Welcome,', 'chrx-rental-manager' ); ?></div>
		<div class="chrx-rm-portal__name"><?php echo esc_html( $tenant['full_name'] ); ?></div>
	</div>
	<div class="chrx-rm-portal__desktop-nav">
		<div class="chrx-rm-portal__desktop-nav-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
		<div class="chrx-rm-portal__desktop-nav-links">
			<a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Log out', 'chrx-rental-manager' ); ?></a>
		</div>
	</div>

	<div class="chrx-rm-portal__empty">
		<div class="chrx-rm-portal__empty-icon" aria-hidden="true">
			<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z M14 2v6h6 M9 15l2 2 4-4"></path></svg>
		</div>
		<div class="chrx-rm-portal__empty-title">
			<?php echo esc_html( $has_lease_history ? __( 'No active lease right now', 'chrx-rental-manager' ) : __( 'No active lease yet', 'chrx-rental-manager' ) ); ?>
		</div>
		<div class="chrx-rm-portal__empty-body">
			<?php if ( $has_lease_history ) : ?>
				<?php esc_html_e( "You don't currently have an active lease with us. If you believe this is a mistake, please contact your property manager.", 'chrx-rental-manager' ); ?>
			<?php else : ?>
				<?php esc_html_e( "Your portal is set up, but your lease hasn't started. Once you move in, your balance, lease details and receipts will appear here.", 'chrx-rental-manager' ); ?>
			<?php endif; ?>
		</div>

		<?php if ( null !== $upcoming_lease ) : ?>
			<?php
			$unit     = $units->find( (int) $upcoming_lease['unit_id'] );
			$property = null !== $unit ? $properties->find( (int) $unit['property_id'] ) : null;
			?>
			<div class="chrx-rm-portal__empty-box">
				<div class="chrx-rm-portal__label" style="margin-bottom:6px;"><?php esc_html_e( 'Expected move-in', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:16px;font-weight:700;color:#12263a;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: move-in date, 2: unit label, 3: property name */
							__( '%1$s · Unit %2$s, %3$s', 'chrx-rental-manager' ),
							gmdate( 'j M Y', strtotime( $upcoming_lease['start_date'] ) ),
							null === $unit ? '' : $unit['unit_label'],
							null === $property ? '' : $property['name']
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( '' !== Settings::company_phone() ) : ?>
			<div class="chrx-rm-portal__empty-footer">
				<?php echo esc_html( sprintf( /* translators: %s: phone number */ __( 'Questions? Call the office on %s.', 'chrx-rental-manager' ), Settings::company_phone() ) ); ?>
			</div>
		<?php endif; ?>
	</div>
</div>
