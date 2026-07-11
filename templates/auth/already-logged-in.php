<?php
/**
 * Shown by any [rental_*] auth shortcode when the visitor is already
 * logged in. Variables in scope: $message (string), $url (string),
 * $link_text (string).
 *
 * @package ChrxRentalManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="chrx-rm-auth chrx-rm-auth--notice">
	<p><?php echo esc_html( $message ); ?></p>
	<p><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $link_text ); ?></a></p>
</div>
