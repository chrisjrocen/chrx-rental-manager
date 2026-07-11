<?php
/**
 * Shown for a receipt id that doesn't exist, or that exists but doesn't
 * belong to the logged-in tenant (PortalContext::lease_belongs_to_tenant()
 * treats both cases identically — SPEC.md §4.5's ID-manipulation guard).
 *
 * Variables in scope: $tenant (array).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Portal\PortalShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$property_name = '';
$active        = PortalShortcode::VIEW_PAYMENTS;
$page_title    = __( 'Receipt', 'chrx-rental-manager' );
?>
<div class="chrx-rm-portal">
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/desktop-nav.php'; ?>
	<?php require \ChrxRentalManager\PLUGIN_DIR . '/templates/portal/partials/mobile-back-header.php'; ?>

	<div class="chrx-rm-portal__content">
		<div class="chrx-rm-portal__card" style="text-align:center;color:#8c8f94;">
			<?php esc_html_e( 'Receipt not found.', 'chrx-rental-manager' ); ?>
		</div>
	</div>
</div>
