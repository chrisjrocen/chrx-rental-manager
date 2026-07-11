<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto-creates the front-end pages the auth shortcodes need a real URL
 * for (login, forgot/reset password, tenant portal activation, and the
 * portal itself) — the same pattern WooCommerce/other shortcode-driven
 * plugins use so "any theme" works without the site owner having to
 * manually create and remember page slugs. Each page's WP post ID is
 * stored in an option so it survives slug changes.
 */
final class Pages {

	public const KEY_LOGIN           = 'login';
	public const KEY_FORGOT_PASSWORD = 'forgot_password';
	public const KEY_RESET_PASSWORD  = 'reset_password';
	public const KEY_PORTAL_ACTIVATE = 'portal_activate';
	public const KEY_PORTAL          = 'portal';

	private const OPTION_PREFIX = 'chrx_rm_page_';

	/**
	 * @return array<string,array{title:string,shortcode:string}>
	 */
	private function definitions(): array {
		return array(
			self::KEY_LOGIN           => array(
				'title'     => __( 'Log In', 'chrx-rental-manager' ),
				'shortcode' => '[rental_login]',
			),
			self::KEY_FORGOT_PASSWORD => array(
				'title'     => __( 'Forgot Password', 'chrx-rental-manager' ),
				'shortcode' => '[rental_forgot_password]',
			),
			self::KEY_RESET_PASSWORD  => array(
				'title'     => __( 'Reset Password', 'chrx-rental-manager' ),
				'shortcode' => '[rental_reset_password]',
			),
			self::KEY_PORTAL_ACTIVATE => array(
				'title'     => __( 'Activate Your Portal', 'chrx-rental-manager' ),
				'shortcode' => '[rental_portal_activate]',
			),
			self::KEY_PORTAL          => array(
				'title'     => __( 'My Account', 'chrx-rental-manager' ),
				'shortcode' => '[rental_portal]',
			),
		);
	}

	/**
	 * Creates any missing page (or recreates one that was deleted out from
	 * under the stored option), idempotent — safe on every activation and
	 * on the admin_init version-bump check.
	 */
	public function ensure_pages_exist(): void {
		foreach ( $this->definitions() as $key => $definition ) {
			$page_id = (int) get_option( self::OPTION_PREFIX . $key );

			if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $definition['title'],
					'post_content' => $definition['shortcode'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
				update_option( self::OPTION_PREFIX . $key, $page_id );
			}
		}
	}

	public function url( string $key ): string {
		$page_id = (int) get_option( self::OPTION_PREFIX . $key );

		if ( $page_id <= 0 ) {
			return home_url( '/' );
		}

		$permalink = get_permalink( $page_id );

		return false === $permalink ? home_url( '/' ) : $permalink;
	}

	public function id( string $key ): int {
		return (int) get_option( self::OPTION_PREFIX . $key );
	}
}
