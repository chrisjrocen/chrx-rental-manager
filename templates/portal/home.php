<?php
/**
 * Portal home / balance overview (designs/29-portal-home.html).
 *
 * Variables in scope: $tenant (array), $lease (array), $unit (?array),
 * $property (?array), $balance (float), $is_overdue (bool),
 * $late_fee_total (float), $next_due_charge (?array).
 * v2 (SPEC.md §4.5/§4.9 — Pay Now): $next_due_charge_outstanding (float),
 * $nylonpay_available (bool), $nylonpay_minimum_amount (float),
 * $pay_reference (string), $pay_error (bool).
 * v2 (SPEC.md §4.8 — custom alerts): $alert_banners (array<int,array>).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;
use ChrxRentalManager\Payments\GatewayRestController;
use ChrxRentalManager\Portal\PortalFormat;
use ChrxRentalManager\Portal\PortalPayNowController;
use ChrxRentalManager\Portal\PortalShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$can_pay_now = $nylonpay_available && null !== $next_due_charge && $next_due_charge_outstanding >= $nylonpay_minimum_amount;

$property_name = null === $property ? '' : $property['name'];
$unit_label    = null === $unit ? '' : $unit['unit_label'];
$portal_url    = ( new \ChrxRentalManager\Auth\Pages() )->url( \ChrxRentalManager\Auth\Pages::KEY_PORTAL );
$lease_url     = add_query_arg( 'rm_view', PortalShortcode::VIEW_LEASE, $portal_url );
$payments_url  = add_query_arg( 'rm_view', PortalShortcode::VIEW_PAYMENTS, $portal_url );
$active        = PortalShortcode::VIEW_HOME;
?>
<div class="chrx-rm-portal">
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/desktop-nav.php'; ?>

	<div class="chrx-rm-portal__mobile-header">
		<div class="chrx-rm-portal__mobile-header-top" style="justify-content:space-between;margin-bottom:16px;">
			<span></span>
			<span><?php echo esc_html( $property_name ); ?></span>
		</div>
		<div class="chrx-rm-portal__welcome"><?php esc_html_e( 'Welcome back,', 'chrx-rental-manager' ); ?></div>
		<div class="chrx-rm-portal__name"><?php echo esc_html( $tenant['full_name'] ); ?></div>
		<div class="chrx-rm-portal__subtitle"><?php echo esc_html( trim( ( '' !== $unit_label ? 'Unit ' . $unit_label : '' ) . ( '' !== $property_name ? ' · ' . $property_name : '' ), ' ·' ) ); ?></div>
	</div>

	<div class="chrx-rm-portal__content">
		<div class="chrx-rm-portal__desktop-heading"><?php esc_html_e( 'Welcome back,', 'chrx-rental-manager' ); ?></div>
		<div class="chrx-rm-portal__desktop-name">
			<?php echo esc_html( $tenant['full_name'] ); ?>
			<span class="chrx-rm-portal__desktop-name-sub"><?php echo esc_html( trim( ( '' !== $unit_label ? '· Unit ' . $unit_label : '' ) . ( '' !== $property_name ? ', ' . $property_name : '' ) ) ); ?></span>
		</div>

		<?php foreach ( $alert_banners as $alert_banner ) : ?>
			<div class="chrx-rm-portal__card" style="background:#fbf0dd;border:1px solid #ecd9ad;">
				<div style="font-weight:700;font-size:14px;margin-bottom:4px;"><?php echo esc_html( $alert_banner['title'] ); ?></div>
				<div style="font-size:13px;color:#5a4a1f;"><?php echo esc_html( $alert_banner['message'] ); ?></div>
			</div>
		<?php endforeach; ?>

		<div class="chrx-rm-portal__card">
			<div class="chrx-rm-portal__label"><?php esc_html_e( 'Current balance', 'chrx-rental-manager' ); ?></div>
			<div class="chrx-rm-portal__balance <?php echo $balance > 0 ? 'chrx-rm-portal__balance--overdue' : 'chrx-rm-portal__balance--clear'; ?>"><?php echo esc_html( Money::format( $balance ) ); ?></div>
			<?php if ( $balance > 0 ) : ?>
				<div style="display:flex;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap;">
					<span class="chrx-rm-portal__pill <?php echo $is_overdue ? 'chrx-rm-portal__pill--overdue' : 'chrx-rm-portal__pill--partial'; ?>">
						<?php echo esc_html( $is_overdue ? __( 'Overdue', 'chrx-rental-manager' ) : __( 'Balance due', 'chrx-rental-manager' ) ); ?>
					</span>
					<?php if ( $late_fee_total > 0 ) : ?>
						<span style="font-size:12px;color:#646970;">
							<?php echo esc_html( sprintf( /* translators: %s: late fee amount */ __( 'incl. %s late fee', 'chrx-rental-manager' ), Money::format( $late_fee_total ) ) ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="chrx-rm-portal__stat-row">
			<div class="chrx-rm-portal__stat">
				<div class="chrx-rm-portal__stat-label"><?php esc_html_e( 'Next due', 'chrx-rental-manager' ); ?></div>
				<?php if ( null !== $next_due_charge ) : ?>
					<div class="chrx-rm-portal__stat-value"><?php echo esc_html( gmdate( 'j M Y', strtotime( $next_due_charge['period_due_date'] ) ) ); ?></div>
					<div class="chrx-rm-portal__stat-sub"><?php echo esc_html( Money::format( (float) $next_due_charge['amount_due'] ) ); ?></div>
				<?php else : ?>
					<div class="chrx-rm-portal__stat-value">&#8212;</div>
					<div class="chrx-rm-portal__stat-sub"><?php esc_html_e( 'Nothing due', 'chrx-rental-manager' ); ?></div>
				<?php endif; ?>
			</div>
			<div class="chrx-rm-portal__stat">
				<div class="chrx-rm-portal__stat-label"><?php esc_html_e( 'Rent per period', 'chrx-rental-manager' ); ?></div>
				<div class="chrx-rm-portal__stat-value"><?php echo esc_html( Money::format( (float) $lease['rent_amount'] ) ); ?></div>
				<div class="chrx-rm-portal__stat-sub"><?php echo esc_html( sprintf( /* translators: %s: ordinal day of month */ __( 'due on the %s', 'chrx-rental-manager' ), PortalFormat::ordinal( (int) $lease['billing_day'] ) ) ); ?></div>
			</div>
		</div>

		<div class="chrx-rm-portal__links">
			<a href="<?php echo esc_url( $payments_url ); ?>" class="chrx-rm-portal__link-row">
				<span><?php esc_html_e( 'Payment history', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__link-chevron">&#8250;</span>
			</a>
			<a href="<?php echo esc_url( $lease_url ); ?>" class="chrx-rm-portal__link-row">
				<span><?php esc_html_e( 'Lease details', 'chrx-rental-manager' ); ?></span>
				<span class="chrx-rm-portal__link-chevron">&#8250;</span>
			</a>
		</div>

		<?php if ( '' !== $pay_reference ) : ?>
			<div class="chrx-rm-portal__pay-note" id="rm-pay-status" data-reference="<?php echo esc_attr( $pay_reference ); ?>" data-status-url="<?php echo esc_url( rest_url( GatewayRestController::NAMESPACE_SLUG . '/gateway-transactions/' . $pay_reference . '/status' ) ); ?>" data-payments-url="<?php echo esc_url( $payments_url ); ?>">
				<?php esc_html_e( 'Waiting for confirmation on your phone — this updates automatically.', 'chrx-rental-manager' ); ?>
			</div>
			<script>
			(function () {
				var el = document.getElementById( 'rm-pay-status' );
				if ( ! el ) { return; }
				var attempts = 0;
				var poll = function () {
					attempts++;
					fetch( el.dataset.statusUrl, { credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( 'successful' === data.status ) {
								window.location = el.dataset.paymentsUrl;
								return;
							}
							if ( 'failed' === data.status || 'cancelled' === data.status || 'expired' === data.status ) {
								el.textContent = <?php echo wp_json_encode( __( 'The payment was not completed. Please try again.', 'chrx-rental-manager' ) ); ?>;
								return;
							}
							if ( attempts < 20 ) {
								setTimeout( poll, 3000 );
							}
						} )
						.catch( function () {
							if ( attempts < 20 ) { setTimeout( poll, 3000 ); }
						} );
				};
				setTimeout( poll, 3000 );
			})();
			</script>
		<?php elseif ( $pay_error ) : ?>
			<div class="chrx-rm-portal__pay-note">
				<?php esc_html_e( 'We could not start that payment. Please check the details and try again.', 'chrx-rental-manager' ); ?>
			</div>
		<?php elseif ( $can_pay_now ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="chrx-rm-portal__pay-now-form">
				<input type="hidden" name="action" value="rm_portal_pay_now">
				<input type="hidden" name="rm_charge_id" value="<?php echo esc_attr( (string) $next_due_charge['id'] ); ?>">
				<?php wp_nonce_field( PortalPayNowController::nonce_action() ); ?>
				<label style="display:block;font-size:12px;color:#646970;margin-bottom:4px;">
					<?php esc_html_e( 'Phone number', 'chrx-rental-manager' ); ?>
					<input type="text" name="rm_phone" value="<?php echo esc_attr( (string) ( $tenant['phone'] ?? '' ) ); ?>" required style="display:block;width:100%;margin-top:4px;">
				</label>
				<label style="display:block;font-size:12px;color:#646970;margin:8px 0 4px;">
					<?php esc_html_e( 'Amount', 'chrx-rental-manager' ); ?>
					<input type="text" name="rm_amount" value="<?php echo esc_attr( (string) $next_due_charge_outstanding ); ?>" required style="display:block;width:100%;margin-top:4px;">
				</label>
				<button type="submit" class="chrx-rm-portal__pay-now-button"><?php esc_html_e( 'Pay Now', 'chrx-rental-manager' ); ?></button>
			</form>
		<?php else : ?>
			<div class="chrx-rm-portal__pay-note">
				<?php esc_html_e( 'Contact your property manager to make a payment.', 'chrx-rental-manager' ); ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="chrx-rm-portal__tabbar">
		<a href="<?php echo esc_url( $portal_url ); ?>" class="chrx-rm-portal__tab chrx-rm-portal__tab--active"><?php esc_html_e( 'Home', 'chrx-rental-manager' ); ?></a>
		<a href="<?php echo esc_url( $lease_url ); ?>" class="chrx-rm-portal__tab"><?php esc_html_e( 'Lease', 'chrx-rental-manager' ); ?></a>
		<a href="<?php echo esc_url( $payments_url ); ?>" class="chrx-rm-portal__tab"><?php esc_html_e( 'Payments', 'chrx-rental-manager' ); ?></a>
	</div>
</div>
