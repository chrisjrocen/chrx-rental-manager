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
use ChrxRentalManager\Cron\Scheduler;
use ChrxRentalManager\Data\Migrator;
use ChrxRentalManager\Portal\PortalReceiptDownload;
use ChrxRentalManager\Portal\PortalShortcode;
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
	private Scheduler $scheduler;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->role_manager = new RoleManager();
		$this->pages        = new Pages();
		$this->scheduler    = new Scheduler();
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
		$this->scheduler->register();

		( new PortalShortcode() )->register();
		( new PortalReceiptDownload() )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_end_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function maybe_upgrade(): void {
		Migrator::maybe_migrate();

		// Idempotent — safe to run on every admin_init.
		$this->role_manager->register_roles();
		$this->pages->ensure_pages_exist();
		$this->scheduler->schedule_events();
	}

	public function role_manager(): RoleManager {
		return $this->role_manager;
	}

	public function enqueue_front_end_assets(): void {
		if ( ! is_page() ) {
			return;
		}

		global $post;

		if ( null === $post ) {
			return;
		}

		$content = (string) $post->post_content;

		if ( has_shortcode( $content, 'rental_portal' ) ) {
			wp_enqueue_style( 'chrx-rm-portal', PLUGIN_URL . 'assets/css/portal.css', array(), VERSION );
		}

		$auth_shortcodes = array( 'rental_login', 'rental_forgot_password', 'rental_reset_password', 'rental_portal_activate' );
		$has_auth_form   = false;

		foreach ( $auth_shortcodes as $shortcode ) {
			if ( has_shortcode( $content, $shortcode ) ) {
				$has_auth_form = true;
				break;
			}
		}

		if ( $has_auth_form ) {
			wp_enqueue_style( 'chrx-rm-auth', PLUGIN_URL . 'assets/css/auth.css', array(), VERSION );
		}
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'chrx-rm' ) && ! str_contains( $hook_suffix, 'chrx-rental-manager' ) ) {
			return;
		}

		wp_enqueue_style( 'chrx-rm-admin', PLUGIN_URL . 'assets/css/admin.css', array(), VERSION );
	}
}
