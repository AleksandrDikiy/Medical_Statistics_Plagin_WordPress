<?php
/**
 * class-migrations.php — Database migration system for Medical Statistics
 *
 * Manages versioned schema changes independently of the plugin version.
 * Each migration runs exactly once and is gated by the stored med_db_version
 * option. Migrations execute in ascending version order.
 *
 * Usage: Med_Migrations::run() — called on plugins_loaded.
 */

declare( strict_types = 1 );

namespace MedicalStatistics;

defined( 'ABSPATH' ) || exit;

final class Med_Migrations {

    /**
     * The highest DB version this class can migrate to.
     * Bump this whenever a new private migration_X_X_X() method is added.
     */
    private const TARGET_VERSION = '1.1.0';

    /**
     * WordPress option key used to persist the current DB version.
     */
    private const OPTION_KEY = 'med_db_version';

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Entry point — hooked to plugins_loaded.
     * Runs every pending migration in version order and persists the new version.
     */
    public static function run(): void {
        $installed = (string) get_option( self::OPTION_KEY, '0.0.0' );

        if ( version_compare( $installed, self::TARGET_VERSION, '>=' ) ) {
            return; // Nothing to do.
        }

        // Run migrations sequentially; each is guarded by its own version check.
        if ( version_compare( $installed, '1.1.0', '<' ) ) {
            self::migration_1_1_0();
        }

        update_option( self::OPTION_KEY, self::TARGET_VERSION, true );
    }

    // -----------------------------------------------------------------------
    // Migrations (one private method per schema version)
    // -----------------------------------------------------------------------

    /**
     * Migration 1.1.0 — Adds multi-user support to wp_med_ordering.
     *
     * Changes:
     *   1. Adds `id_user` BIGINT(20) UNSIGNED column (nullable, references wp_users).
     *   2. Backfills all existing rows with id_user = 1 (site Administrator).
     *   3. Adds a foreign-key constraint (silently skipped if it already exists
     *      or if the storage engine does not support FK — e.g. MyISAM).
     */
    private static function migration_1_1_0(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'med_ordering';

        // Guard: only ALTER if the column does not already exist.
        $col_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'id_user'",
                DB_NAME,
                $table
            )
        );

        if ( ! (int) $col_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN id_user BIGINT(20) UNSIGNED NULL DEFAULT NULL
                 AFTER branch_info,
                 ADD KEY idx_id_user (id_user)"
            );

            // Attempt to add FK — suppress errors for environments that do
            // not support it (MyISAM, Galera strict mode, etc.).
            $prev = $wpdb->suppress_errors( true );
            $wpdb->query(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT fk_ord_user
                   FOREIGN KEY (id_user) REFERENCES {$wpdb->users}(ID)
                   ON DELETE SET NULL"
            );
            $wpdb->suppress_errors( $prev );
        }

        // Data backfill: assign Administrator (ID = 1) to all legacy rows.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "UPDATE {$table} SET id_user = 1 WHERE id_user IS NULL OR id_user = 0"
        );
    }
}
