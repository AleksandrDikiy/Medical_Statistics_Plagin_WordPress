<?php
namespace MedicalStatistics;
defined('ABSPATH') || exit;

function med_stat_create_tables(): void {
    global $wpdb;
    $c    = $wpdb->get_charset_collate();
    $ind  = $wpdb->prefix . 'med_indicator';
    $ord  = $wpdb->prefix . 'med_ordering';
    $meas = $wpdb->prefix . 'med_measurement';

    $sql_ind = "CREATE TABLE {$ind} (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(500) NOT NULL DEFAULT '',
  min decimal(12,4) DEFAULT NULL,
  max decimal(12,4) DEFAULT NULL,
  measure varchar(100) DEFAULT NULL,
  category varchar(100) DEFAULT NULL,
  interpretation_hint mediumtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY idx_name (name(250))
) ENGINE=InnoDB {$c};";

    $sql_ord = "CREATE TABLE {$ord} (
  id int(11) NOT NULL AUTO_INCREMENT,
  order_number varchar(50) DEFAULT NULL,
  patient_name varchar(500) NOT NULL DEFAULT '',
  patient_dob date DEFAULT NULL,
  collection_date datetime DEFAULT NULL,
  doctor_name varchar(500) DEFAULT NULL,
  branch_info mediumtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_number (order_number),
  KEY idx_collection_date (collection_date)
) ENGINE=InnoDB {$c};";

    $sql_meas = "CREATE TABLE {$meas} (
  id int(11) NOT NULL AUTO_INCREMENT,
  id_order int(11) DEFAULT NULL,
  id_indicator int(11) DEFAULT NULL,
  result_value decimal(10,3) DEFAULT NULL,
  execution_date datetime DEFAULT NULL,
  is_normal tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_id_order (id_order),
  KEY idx_id_indicator (id_indicator)
) ENGINE=InnoDB {$c};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_ind);
    dbDelta($sql_ord);
    dbDelta($sql_meas);

    // FK (ігноруємо помилки якщо вже існують)
    $prev = $wpdb->suppress_errors(true);
    $wpdb->query("ALTER TABLE {$meas}
        ADD CONSTRAINT fk_meas_ord FOREIGN KEY (id_order) REFERENCES {$ord}(id) ON DELETE CASCADE,
        ADD CONSTRAINT fk_meas_ind FOREIGN KEY (id_indicator) REFERENCES {$ind}(id) ON DELETE RESTRICT");
    $wpdb->suppress_errors($prev);

    update_option('med_stat_db_version', MED_STAT_VERSION);
    wp_cache_delete('med_stat_tables_exist');
}

function med_stat_ensure_tables(): void {
    if (wp_cache_get('med_stat_tables_exist') === 'yes') return;
    global $wpdb;
    $t = $wpdb->prefix . 'med_ordering';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t))) {
        wp_cache_set('med_stat_tables_exist', 'yes');
        return;
    }
    med_stat_create_tables();
    wp_cache_set('med_stat_tables_exist', 'yes');
}

function med_stat_maybe_migrate(): void {
    if (version_compare(get_option('med_stat_db_version','0'), MED_STAT_VERSION, '<')) {
        med_stat_create_tables();
    }
}
add_action('plugins_loaded', __NAMESPACE__ . '\\med_stat_maybe_migrate');
