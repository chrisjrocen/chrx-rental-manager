<?php
/**
 * Landlord statement PDF preview (designs/22-landlord-statement-generator.html),
 * staff/admin side.
 *
 * Variables in scope: $property (array), $from (string), $to (string),
 * $download_url (string), $list_url (string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Landlord statement', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php echo esc_html( $property['name'] ); ?>
	</div>
	<h1 style="font-size:23px;font-weight:600;margin:0 0 18px;">
		<?php
		printf(
			/* translators: 1: property name, 2: start date, 3: end date */
			esc_html__( 'Statement — %1$s (%2$s to %3$s)', 'chrx-rental-manager' ),
			esc_html( $property['name'] ),
			esc_html( gmdate( 'j M Y', strtotime( $from ) ) ),
			esc_html( gmdate( 'j M Y', strtotime( $to ) ) )
		);
		?>
	</h1>

	<div style="display:grid;grid-template-columns:1fr 260px;gap:20px;max-width:900px;">
		<div style="background:#e9ebef;border:1px solid #dcdcde;border-radius:6px;padding:26px;display:flex;justify-content:center;">
			<iframe src="<?php echo esc_url( $download_url ); ?>" title="<?php esc_attr_e( 'Statement PDF preview', 'chrx-rental-manager' ); ?>" style="width:100%;max-width:520px;height:640px;border:none;box-shadow:0 3px 14px rgba(0,0,0,.12);background:#fff;"></iframe>
		</div>
		<div style="display:flex;flex-direction:column;gap:14px;">
			<a href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" class="button button-primary" style="text-align:center;"><?php esc_html_e( 'Download PDF', 'chrx-rental-manager' ); ?></a>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button" style="text-align:center;"><?php esc_html_e( 'Back', 'chrx-rental-manager' ); ?></a>
		</div>
	</div>
</div>
