<?php

declare( strict_types = 1 );

namespace ChrxRentalManager;

use ChrxRentalManager\Admin\Menu;
use ChrxRentalManager\Admin\TenantInviteController;
use ChrxRentalManager\Auth\ForgotPasswordForm;
use ChrxRentalManager\Auth\LoginForm;
use ChrxRentalManager\Auth\Pages;
use ChrxRentalManager\Auth\PortalActivateForm;
use ChrxRentalManager\Auth\Redirector;
use ChrxRentalManager\Auth\ResetPasswordForm;
use ChrxRentalManager\Data\Migrator;
use ChrxRentalManager\Roles\RoleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin container. Instantiated once on `plugins_loaded`; wires up
 * the subsystems added in later phases (admin screens, cron, portal).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private RoleManager $role_manager;
	private Pages $pages;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->role_manager = new RoleManager();
		$this->pages        = new Pages();
	}

	public function init(): void {
		load_plugin_textdomain(
			'chrx-rental-manager',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);

		// Detects a schema/role/pages version bump without requiring a
		// full deactivate/reactivate cycle (e.g. plugin updated in place).
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		( new Redirector() )->register();
		( new LoginForm() )->register();
		( new ForgotPasswordForm() )->register();
		( new ResetPasswordForm() )->register();
		( new PortalActivateForm() )->register();
		( new TenantInviteController() )->register();
		( new Menu() )->register();

		// Placeholder for [rental_portal] — the full tenant portal is
		// built in the Tenant Self-Service Portal phase; this just avoids
		// a bare, unrendered shortcode tag on the auto-created portal page
		// in the meantime (Redirector already sends tenants here on login).
		add_shortcode( 'rental_portal', array( $this, 'render_portal_placeholder' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_end_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function maybe_upgrade(): void {
		Migrator::maybe_migrate();

		// Idempotent — safe to run on every admin_init.
		$this->role_manager->register_roles();
		$this->pages->ensure_pages_exist();
	}

	public function role_manager(): RoleManager {
		return $this->role_manager;
	}

	public function render_portal_placeholder(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your tenant portal.', 'chrx-rental-manager' ) . '</p>';
		}

		return '<p>' . esc_html__( 'Your tenant portal is being set up. Please check back soon.', 'chrx-rental-manager' ) . '</p>';
	}

	public function enqueue_front_end_assets(): void {
		if ( ! is_page() ) {
			return;
		}

		global $post;

		if ( null === $post ) {
			return;
		}

		$auth_shortcodes = array( 'rental_login', 'rental_forgot_password', 'rental_reset_password', 'rental_portal_activate' );
		$content         = (string) $post->post_content;
		$has_auth_form   = false;

		foreach ( $auth_shortcodes as $shortcode ) {
			if ( has_shortcode( $content, $shortcode ) ) {
				$has_auth_form = true;
				break;
			}
		}

		if ( ! $has_auth_form ) {
			return;
		}

		wp_enqueue_style( 'chrx-rm-auth', PLUGIN_URL . 'assets/css/auth.css', array(), VERSION );
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'chrx-rm' ) && ! str_contains( $hook_suffix, 'chrx-rental-manager' ) ) {
			return;
		}

		wp_enqueue_style( 'chrx-rm-admin', PLUGIN_URL . 'assets/css/admin.css', array(), VERSION );
	}
}
