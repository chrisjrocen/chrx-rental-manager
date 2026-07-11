<?php
/**
 * Payment confirmation / receipt view (designs/19-receipt-preview.html):
 * PDF preview (embedded), email-to-tenant action, download/print links.
 *
 * Variables in scope: $receipt (array), $payment (array), $lease (?array),
 * $unit (?array), $tenant (?array), $property (?array), $download_url
 * (string), $email_url (string), $list_url (string), $notice (?string).
 *
 * @package ChrxRentalManager
 */

use ChrxRentalManager\Admin\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenant_name  = null === $tenant ? '' : $tenant['full_name'];
$tenant_email = null === $tenant ? '' : (string) $tenant['email'];
?>
<div class="wrap chrx-rm-admin">
	<div class="chrx-rm-breadcrumb">
		<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Payments', 'chrx-rental-manager' ); ?></a> &rsaquo; <?php esc_html_e( 'Receipt', 'chrx-rental-manager' ); ?>
	</div>

	<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
		<div style="width:26px;height:26px;border-radius:50%;background:#e5f5eb;display:flex;align-items:center;justify-content:center;">
			<span style="color:#0a7d34;font-weight:800;">&#10003;</span>
		</div>
		<h1 style="font-size:22px;font-weight:600;margin:0;"><?php esc_html_e( 'Payment recorded', 'chrx-rental-manager' ); ?></h1>
	</div>
	<p style="font-size:13px;color:#646970;margin:0 0 18px;">
		<?php
		printf(
			/* translators: 1: receipt number, 2: tenant name */
			esc_html__( 'Receipt #%1$s generated for %2$s.', 'chrx-rental-manager' ),
			esc_html( $receipt['receipt_number'] ),
			esc_html( $tenant_name )
		);
		?>
	</p>

	<?php if ( null !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:20px;max-width:1100px;">
		<div style="background:#e9ebef;border:1px solid #dcdcde;border-radius:6px;padding:26px;display:flex;justify-content:center;">
			<iframe src="<?php echo esc_url( $download_url ); ?>" title="<?php esc_attr_e( 'Receipt PDF preview', 'chrx-rental-manager' ); ?>" style="width:100%;max-width:420px;height:560px;border:none;box-shadow:0 3px 14px rgba(0,0,0,.12);background:#fff;"></iframe>
		</div>
		<div style="display:flex;flex-direction:column;gap:14px;">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;">
				<div style="font-weight:700;font-size:14px;margin-bottom:12px;"><?php esc_html_e( 'Email receipt to tenant', 'chrx-rental-manager' ); ?></div>
				<div style="font-size:13px;color:#50575e;margin-bottom:6px;"><?php esc_html_e( 'To', 'chrx-rental-manager' ); ?></div>
				<div style="background:#f6f7f7;border-radius:5px;padding:9px 12px;font-size:13px;margin-bottom:14px;">
					<?php echo esc_html( '' !== $tenant_email ? $tenant_email : __( '(no email on file)', 'chrx-rental-manager' ) ); ?>
				</div>
				<?php if ( '' !== $tenant_email ) : ?>
					<a href="<?php echo esc_url( $email_url ); ?>" style="display:block;text-align:center;width:100%;box-sizing:border-box;background:#2271b1;color:#fff;border:none;border-radius:4px;padding:10px;font-size:14px;font-weight:600;text-decoration:none;">
						<?php echo esc_html( null !== $receipt['emailed_at'] ? __( 'Resend receipt email', 'chrx-rental-manager' ) : __( 'Email receipt', 'chrx-rental-manager' ) ); ?>
					</a>
				<?php else : ?>
					<button type="button" class="button" style="width:100%;" disabled title="<?php esc_attr_e( 'This tenant has no email on file.', 'chrx-rental-manager' ); ?>"><?php esc_html_e( 'Email receipt', 'chrx-rental-manager' ); ?></button>
				<?php endif; ?>
			</div>
			<div style="display:flex;gap:10px;">
				<a href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" style="flex:1;text-align:center;background:#f6f7f7;color:#2271b1;border:1px solid #2271b1;border-radius:4px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;"><?php esc_html_e( 'Download PDF', 'chrx-rental-manager' ); ?></a>
				<a href="<?php echo esc_url( $download_url ); ?>" target="_blank" rel="noopener" style="flex:1;text-align:center;background:#f6f7f7;color:#50575e;border:1px solid #c3c4c7;border-radius:4px;padding:9px;font-size:13px;text-decoration:none;"><?php esc_html_e( 'Print', 'chrx-rental-manager' ); ?></a>
			</div>
			<?php if ( null !== $receipt['emailed_at'] ) : ?>
				<div style="background:#e5f5eb;border:1px solid #b6e0c2;border-radius:6px;padding:12px 14px;font-size:12px;color:#0a7d34;">
					<?php
					printf(
						/* translators: %s: date/time emailed */
						esc_html__( 'Emailed to tenant on %s.', 'chrx-rental-manager' ),
						esc_html( gmdate( 'j M Y, g:ia', strtotime( $receipt['emailed_at'] ) ) )
					);
					?>
				</div>
			<?php endif; ?>
			<div style="background:#e5f5eb;border:1px solid #b6e0c2;border-radius:6px;padding:12px 14px;font-size:12px;color:#0a7d34;">
				<?php
				printf(
					/* translators: %s: payment amount */
					esc_html__( 'Ledger updated — %s recorded.', 'chrx-rental-manager' ),
					esc_html( Money::format( (float) $payment['amount'] ) )
				);
				?>
			</div>
		</div>
	</div>
</div>
