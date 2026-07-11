<?php

declare( strict_types = 1 );

namespace ChrxRentalManager\Cli;

use ChrxRentalManager\Data\Charge;
use ChrxRentalManager\Data\Lease;
use ChrxRentalManager\Data\Payment;
use ChrxRentalManager\Data\Property;
use ChrxRentalManager\Data\Tenant;
use ChrxRentalManager\Data\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dev-only sample data generator — `wp chrx-rm seed`. Not autoloaded into
 * any production request path; only registered when WP_CLI is present
 * (see chrx-rental-manager.php bootstrap()). Names/amounts loosely mirror
 * designs/*.html so seeded data visually matches the mockups during QA.
 */
final class SeedCommand {

	private Property $properties;
	private Unit $units;
	private Tenant $tenants;
	private Lease $leases;
	private Charge $charges;
	private Payment $payments;

	public function __construct() {
		$this->properties = new Property();
		$this->units      = new Unit();
		$this->tenants    = new Tenant();
		$this->leases     = new Lease( $this->units );
		$this->charges    = new Charge();
		$this->payments   = new Payment();
	}

	/**
	 * Generates a small set of properties, units, tenants, leases, charges
	 * and payments for manual QA against the designs.
	 *
	 * ## OPTIONS
	 *
	 * [--fresh]
	 * : Also wipes previously seeded rm_* rows before seeding (destructive,
	 *   dev-only — never run against real data).
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrx-rm seed
	 *     wp chrx-rm seed --fresh
	 */
	public function run( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['fresh'] ) ) {
			$this->wipe();
		}

		$today = current_time( 'Y-m-d' );

		$property_ids = array(
			$this->properties->insert(
				array(
					'name'    => 'Sunrise Residents',
					'address' => '14 Independence Ave',
					'city'    => 'Accra',
					'notes'   => 'Gated compound, 6 units.',
				)
			),
			$this->properties->insert(
				array(
					'name'    => 'Cedar Heights',
					'address' => '8 Ridge Road',
					'city'    => 'Kumasi',
					'notes'   => '',
				)
			),
			$this->properties->insert(
				array(
					'name'    => 'Acacia Court',
					'address' => '22 Liberation Rd',
					'city'    => 'Accra',
					'notes'   => '',
				)
			),
		);

		$tenant_names = array(
			'Kwame Asante',
			'Ama Boateng',
			'Kofi Sarpong',
			'Efua Sarpong',
			'Kojo Antwi',
			'Yaw Darko',
			'Daniel Osei',
			'Grace Owusu',
		);

		$unit_labels = array( 'A1', 'A2', 'A3', 'B1', 'B4', 'C2' );

		$unit_ids   = array();
		$tenant_ids = array();

		foreach ( $property_ids as $pi => $property_id ) {
			foreach ( array_slice( $unit_labels, 0, 2 ) as $label ) {
				$unit_ids[] = $this->units->insert(
					array(
						'property_id' => $property_id,
						'unit_label'  => 'Unit ' . $label,
						'bedrooms'    => wp_rand( 1, 3 ),
						'rent_amount' => (string) wp_rand( 9, 22 ) * 100,
						'status'      => Unit::STATUS_VACANT,
						'notes'       => '',
					)
				);
			}
		}

		foreach ( $tenant_names as $name ) {
			$slug         = sanitize_title( $name );
			$tenant_ids[] = $this->tenants->insert(
				array(
					'wp_user_id'  => null,
					'full_name'   => $name,
					'phone'       => '0' . wp_rand( 200000000, 599999999 ),
					'email'       => $slug . '@example.com',
					'national_id' => '',
					'status'      => Tenant::STATUS_ACTIVE,
				)
			);
		}

		// Lease most units, leave one vacant per property for the empty-state screens.
		$leased_units = array_slice( $unit_ids, 0, count( $unit_ids ) - 1 );

		foreach ( $leased_units as $i => $unit_id ) {
			$tenant_id = $tenant_ids[ $i % count( $tenant_ids ) ];
			$unit      = $this->units->find( $unit_id );
			$rent      = (float) $unit['rent_amount'];

			$lease_id = $this->leases->create(
				array(
					'unit_id'        => $unit_id,
					'tenant_id'      => $tenant_id,
					'start_date'     => gmdate( 'Y-m-d', strtotime( '-6 months', strtotime( $today ) ) ),
					'end_date'       => gmdate( 'Y-m-d', strtotime( '+6 months', strtotime( $today ) ) ),
					'rent_amount'    => $rent,
					'billing_day'    => 1,
					'deposit_amount' => $rent * 2,
					'deposit_status' => 'paid',
					'status'         => Lease::STATUS_ACTIVE,
				)
			);

			// Two past periods: one paid in full, one outstanding — gives the
			// ledger/dashboard/reports screens something to show.
			$paid_charge_id = $this->charges->insert(
				array(
					'lease_id'        => $lease_id,
					'period_start'    => gmdate( 'Y-m-d', strtotime( '-2 months', strtotime( $today ) ) ),
					'period_due_date' => gmdate( 'Y-m-d', strtotime( '-2 months +1 day', strtotime( $today ) ) ),
					'amount_due'      => $rent,
					'type'            => Charge::TYPE_RENT,
					'status'          => Charge::STATUS_PAID,
				)
			);

			$this->payments->insert(
				array(
					'lease_id'       => $lease_id,
					'charge_id'      => $paid_charge_id,
					'amount'         => $rent,
					'method'         => Payment::METHOD_MTN_MOMO,
					'reference_note' => 'Seed data',
					'recorded_by'    => get_current_user_id(),
					'receipt_id'     => null,
					'paid_at'        => gmdate( 'Y-m-d H:i:s', strtotime( '-2 months', strtotime( $today ) ) ),
				)
			);

			$outstanding_status = 0 === $i % 3 ? Charge::STATUS_UNPAID : Charge::STATUS_PARTIAL;

			$open_charge_id = $this->charges->insert(
				array(
					'lease_id'        => $lease_id,
					'period_start'    => gmdate( 'Y-m-d', strtotime( '-1 month', strtotime( $today ) ) ),
					'period_due_date' => gmdate( 'Y-m-d', strtotime( '-1 month +1 day', strtotime( $today ) ) ),
					'amount_due'      => $rent,
					'type'            => Charge::TYPE_RENT,
					'status'          => $outstanding_status,
				)
			);

			if ( Charge::STATUS_PARTIAL === $outstanding_status ) {
				$this->payments->insert(
					array(
						'lease_id'       => $lease_id,
						'charge_id'      => $open_charge_id,
						'amount'         => round( $rent / 2, 2 ),
						'method'         => Payment::METHOD_CASH,
						'reference_note' => 'Seed data — partial payment',
						'recorded_by'    => get_current_user_id(),
						'receipt_id'     => null,
						'paid_at'        => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days', strtotime( $today ) ) ),
					)
				);
			}
		}

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::success(
				sprintf(
					'Seeded %d properties, %d units, %d tenants, %d leases.',
					count( $property_ids ),
					count( $unit_ids ),
					count( $tenant_ids ),
					count( $leased_units )
				)
			);
		}
	}

	private function wipe(): void {
		global $wpdb;

		foreach ( array( 'rm_receipts', 'rm_payments', 'rm_charges', 'rm_leases', 'rm_tenants', 'rm_units', 'rm_properties', 'rm_notifications_log' ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, fixed list above, dev-only command.
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
		}
	}
}
