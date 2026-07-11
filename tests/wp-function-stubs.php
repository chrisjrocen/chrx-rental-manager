<?php
/**
 * Minimal stubs for WordPress functions used by pure-unit (non-integration)
 * tests, so plain business-logic classes can be tested without booting a
 * full WordPress environment. Integration tests (tests/Integration) use the
 * real WP test suite instead of these stubs.
 */

declare( strict_types = 1 );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ) {
		return null;
	}
}

if ( ! function_exists( 'add_role' ) ) {
	function add_role( string $role, string $display_name, array $capabilities = [] ) {
		return null;
	}
}

if ( ! function_exists( 'remove_role' ) ) {
	function remove_role( string $role ): void {}
}
