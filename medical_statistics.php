<?php
/**
 * Plugin Name:  Medical Statistics
 * Plugin URI:   #
 * Description:  Трекер медичних показників — імпорт PDF CSD LAB, зберігання, візуалізація.
 * Version:      1.5.4
 * Author:       Dev
 * Text Domain:  med-stat
 */

declare( strict_types = 1 );

namespace MedicalStatistics;

defined( 'ABSPATH' ) || exit;

/* ── Constants ─────────────────────────────────────────────────────────── */
define( 'MED_STAT_VERSION', '1.5.4' );
define( 'MED_STAT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MED_STAT_URL',     plugin_dir_url( __FILE__ ) );

/**
 * Database schema version — independent of the plugin version.
 * Bump this together with a new Med_Migrations::migration_X_X_X() method
 * whenever the DB schema changes.
 */
if ( ! defined( 'MED_DB_VERSION' ) ) {
    define( 'MED_DB_VERSION', '1.1.1' );
}

/* ── Includes ───────────────────────────────────────────────────────────── */
require_once MED_STAT_DIR . 'includes/db-setup.php';
require_once MED_STAT_DIR . 'includes/class-migrations.php';
require_once MED_STAT_DIR . 'includes/class-pdf-parser.php';
require_once MED_STAT_DIR . 'includes/class-import-csd.php';

/* ── Activation ─────────────────────────────────────────────────────────── */
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
function activate(): void {
    med_stat_create_tables();
    med_stat_add_role();
    // Trigger migrations immediately on activation so the schema is always
    // up-to-date when the plugin is re-activated after an update.
    Med_Migrations::run();
    flush_rewrite_rules();
}

function med_stat_add_role(): void {
    if ( ! get_role( 'medical_statistics' ) ) {
        add_role(
            'medical_statistics',
            __( 'Medical Statistics', 'med-stat' ),
            [ 'read' => true, 'medical_statistics' => true ]
        );
    }
}

/* ── Run migrations on every request (no-op when already up-to-date) ────── */
add_action( 'plugins_loaded', __NAMESPACE__ . '\\run_migrations', 20 );
function run_migrations(): void {
    Med_Migrations::run();
}

/* ── Admin notice if tables are missing ─────────────────────────────────── */
add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_tables' );
function admin_notice_tables(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'med_ordering' ) ) ) return;
    $url = wp_nonce_url( admin_url( 'admin-post.php?action=med_stat_create_tables' ), 'med_stat_create_tables' );
    echo '<div class="notice notice-error"><p><strong>Medical Statistics:</strong> Таблиці БД не створено. '
        . '<a href="' . esc_url( $url ) . '" class="button button-primary" style="margin-left:10px">Створити зараз</a></p></div>';
}

add_action( 'admin_post_med_stat_create_tables', __NAMESPACE__ . '\\handle_create_tables' );
function handle_create_tables(): void {
    check_admin_referer( 'med_stat_create_tables' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    delete_option( 'med_stat_db_version' );
    delete_option( 'med_db_version' );        // also reset migration version
    med_stat_create_tables();
    med_stat_add_role();
    Med_Migrations::run();
    wp_redirect( admin_url( 'plugins.php?med_stat_tables=created' ) );
    exit;
}

add_action( 'admin_notices', __NAMESPACE__ . '\\admin_notice_created' );
function admin_notice_created(): void {
    if ( isset( $_GET['med_stat_tables'] ) && $_GET['med_stat_tables'] === 'created' ) {
        echo '<div class="notice notice-success is-dismissible"><p>Medical Statistics: таблиці створено.</p></div>';
    }
}

/* ── Assets ─────────────────────────────────────────────────────────────── */
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
function enqueue_assets(): void {
    if ( ! current_user_can( 'medical_statistics' ) ) return;

    $vCss = MED_STAT_VERSION . '.' . filemtime( MED_STAT_DIR . 'css/med_stat.css' );
    $vJs  = MED_STAT_VERSION . '.' . filemtime( MED_STAT_DIR . 'js/med_stat.js' );

    wp_enqueue_style( 'med-stat', MED_STAT_URL . 'css/med_stat.css', [], $vCss );
    wp_enqueue_script( 'med-stat', MED_STAT_URL . 'js/med_stat.js', [ 'jquery' ], $vJs, true );

    wp_localize_script( 'med-stat', 'medStat', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'med_stat_nonce' ),
        // Expose admin flag so JS can show/hide the user filter.
        'isAdmin' => current_user_can( 'manage_options' ),
        'i18n'    => [
            'loading'      => __( 'Завантаження…', 'med-stat' ),
            'importOk'     => __( 'Імпорт успішний.', 'med-stat' ),
            'importFail'   => __( 'Помилка імпорту.', 'med-stat' ),
            'noResults'    => __( 'Нічого не знайдено.', 'med-stat' ),
            'orderLabel'   => __( 'Замовлення №', 'med-stat' ),
            'deleteConfirm'=> __( 'Видалити це замовлення та всі його результати?', 'med-stat' ),
            'deleteOk'     => __( 'Замовлення видалено.', 'med-stat' ),
            'saveOk'       => __( 'Збережено.', 'med-stat' ),
            'allUsers'     => __( 'Усі користувачі', 'med-stat' ),
        ],
    ] );
}

/* ── Shortcode: [medical_statistics] ────────────────────────────────────── */
add_shortcode( 'medical_statistics', __NAMESPACE__ . '\\render_shortcode' );
function render_shortcode(): string {
    if ( ! current_user_can( 'medical_statistics' ) ) {
        return '<p class="med-stat-denied">' . esc_html__( 'Доступ заборонено.', 'med-stat' ) . '</p>';
    }
    ob_start();
    require MED_STAT_DIR . 'views/order.php';
    return (string) ob_get_clean();
}

/* ── Shortcode: [medical_analit] ─────────────────────────────────────────── */
add_shortcode( 'medical_analit', __NAMESPACE__ . '\\render_analit_shortcode' );
function render_analit_shortcode(): string {
    if ( ! current_user_can( 'medical_statistics' ) ) {
        return '<p class="med-stat-denied">' . esc_html__( 'Доступ заборонено.', 'med-stat' ) . '</p>';
    }
    ob_start();
    require MED_STAT_DIR . 'views/med_analit.php';
    return (string) ob_get_clean();
}

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_analit_assets' );
function enqueue_analit_assets(): void {
    if ( ! current_user_can( 'medical_statistics' ) ) return;

    $vCss = MED_STAT_VERSION . '.' . ( file_exists( MED_STAT_DIR . 'css/med_analit.css' ) ? filemtime( MED_STAT_DIR . 'css/med_analit.css' ) : '1' );
    $vJs  = MED_STAT_VERSION . '.' . ( file_exists( MED_STAT_DIR . 'js/med_analit.js' )  ? filemtime( MED_STAT_DIR . 'js/med_analit.js' )  : '1' );

    wp_enqueue_style( 'med-analit', MED_STAT_URL . 'css/med_analit.css', [], $vCss );
    wp_enqueue_script( 'chartjs',            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',                              [], '4.4.3', true );
    wp_enqueue_script( 'chartjs-annotation', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js', [ 'chartjs' ], '3.0.1', true );
    wp_enqueue_script( 'med-analit',         MED_STAT_URL . 'js/med_analit.js', [ 'jquery', 'chartjs', 'chartjs-annotation' ], $vJs, true );

    wp_localize_script( 'med-analit', 'medAnalit', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'med_stat_nonce' ),
        'i18n'    => [
            'loading'  => __( 'Завантаження…', 'med-stat' ),
            'noData'   => __( 'Немає даних для цього показника в обраному діапазоні.', 'med-stat' ),
            'errorNet' => __( 'Помилка мережі. Спробуйте ще раз.', 'med-stat' ),
            'selectInd'=> __( 'Оберіть показник у фільтрі вище', 'med-stat' ),
        ],
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   SHARED SECURITY HELPERS
   ════════════════════════════════════════════════════════════════════════════ */

/**
 * Verify nonce + capability. Terminates with a JSON 403 on failure.
 */
function ajax_guard(): void {
    check_ajax_referer( 'med_stat_nonce', 'nonce' );
    if ( ! current_user_can( 'medical_statistics' ) ) {
        wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'med-stat' ) ], 403 );
    }
}

/**
 * Returns true when the current user is a site Administrator.
 */
function is_admin_user(): bool {
    return current_user_can( 'manage_options' );
}

/**
 * Append a row-level security WHERE clause fragment when the current user
 * is NOT an administrator. The returned string is already SQL-safe; any
 * required positional argument is appended to &$args.
 *
 * @param  array<int|string> &$args  Existing prepared-statement argument list.
 * @return string  Either '' (admin — no restriction) or ' AND id_user = %d'.
 */
function rls_clause( array &$args ): string {
    if ( is_admin_user() ) {
        return '';
    }
    $args[] = get_current_user_id();
    return ' AND id_user = %d';
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX: Chart data for [medical_analit]
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_chart_data', __NAMESPACE__ . '\\ajax_chart_data' );
function ajax_chart_data(): void {
    ajax_guard();
    global $wpdb;
    $tInd  = $wpdb->prefix . 'med_indicator';
    $tMeas = $wpdb->prefix . 'med_measurement';

    $indId    = absint( $_POST['ind_id']    ?? 0 );
    $dateFrom = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
    $dateTo   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

    if ( ! $indId ) {
        wp_send_json_error( [ 'message' => __( 'Оберіть показник.', 'med-stat' ) ] );
    }

    $dtFrom = $dateFrom ? $dateFrom . ' 00:00:00' : '1900-01-01 00:00:00';
    $dtTo   = $dateTo   ? $dateTo   . ' 23:59:59' : '2099-12-31 23:59:59';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT i.id, i.name, i.min, i.max, i.measure,
                m.result_value, m.execution_date, m.is_normal
         FROM {$tInd} i
         JOIN {$tMeas} m ON m.id_indicator = i.id
         WHERE i.id = %d
           AND m.execution_date BETWEEN %s AND %s
         ORDER BY m.execution_date ASC",
        $indId, $dtFrom, $dtTo
    ) );

    if ( empty( $rows ) ) {
        wp_send_json_error( [
            'message' => __( 'Немає даних для цього показника в обраному діапазоні.', 'med-stat' ),
            'empty'   => true,
        ] );
    }

    $meta   = $rows[0];
    $labels = [];
    $values = [];
    foreach ( $rows as $row ) {
        $ts       = $row->execution_date ? strtotime( $row->execution_date ) : 0;
        $labels[] = $ts ? date( 'd.m.Y', $ts ) : '—';
        $values[] = (float) $row->result_value;
    }

    wp_send_json_success( [
        'name'    => $meta->name,
        'min'     => $meta->min !== null ? (float) $meta->min : null,
        'max'     => $meta->max !== null ? (float) $meta->max : null,
        'measure' => $meta->measure ?? '',
        'labels'  => $labels,
        'values'  => $values,
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 1 — GET ORDERS LIST (with row-level security + admin user filter)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_get_orders', __NAMESPACE__ . '\\ajax_get_orders' );
function ajax_get_orders(): void {
    ajax_guard();
    global $wpdb;

    $page   = max( 1, absint( $_POST['page']      ?? 1 ) );
    $per    = 10;
    $offset = ( $page - 1 ) * $per;
    $from   = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
    $to     = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );
    $search = sanitize_text_field( wp_unslash( $_POST['search']    ?? '' ) );

    if ( ! $from ) $from = date( 'Y-m-d', strtotime( '-365 days' ) );
    if ( ! $to   ) $to   = date( 'Y-m-d' );

    $t       = $wpdb->prefix . 'med_ordering';
    $wheres  = [ '1=1' ];
    $args    = [];

    // Date range filter.
    $wheres[] = 'DATE(COALESCE(collection_date,created_at)) >= %s';
    $args[]   = $from;
    $wheres[] = 'DATE(COALESCE(collection_date,created_at)) <= %s';
    $args[]   = $to;

    // Search filter.
    if ( $search ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $wheres[] = '(order_number LIKE %s OR patient_name LIKE %s)';
        $args[]   = $like;
        $args[]   = $like;
    }

    // Admin-only user filter: allows an administrator to scope the list to a
    // specific user via the UI dropdown.  Regular users never reach this branch.
    $filterUserId = absint( $_POST['user_id'] ?? 0 );
    if ( is_admin_user() && $filterUserId > 0 ) {
        $wheres[] = 'id_user = %d';
        $args[]   = $filterUserId;
    }

    // Row-level security: non-admins see only their own records.
    $wheres[] = '1=1' . rls_clause( $args );   // rls_clause appends to $args

    $where = implode( ' AND ', $wheres );

    $total  = (int) $wpdb->get_var(
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", ...$args )
    );
    $dArgs  = array_merge( $args, [ $per, $offset ] );
    $orders = $wpdb->get_results(
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare(
            "SELECT id, order_number, patient_name, collection_date, created_at, id_user
             FROM {$t} WHERE {$where}
             ORDER BY COALESCE(collection_date,created_at) DESC
             LIMIT %d OFFSET %d",
            ...$dArgs
        )
    );

    wp_send_json_success( [
        'orders'       => array_map( static fn( $o ) => [
            'id'              => absint( $o->id ),
            'order_number'    => $o->order_number,
            'patient_name'    => $o->patient_name,
            'collection_date' => $o->collection_date,
        ], $orders ),
        'total'        => $total,
        'pages'        => (int) ceil( $total / $per ),
        'current_page' => $page,
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 2 — GET ORDER DETAIL (with row-level security)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_get_order', __NAMESPACE__ . '\\ajax_get_order' );
function ajax_get_order(): void {
    ajax_guard();
    global $wpdb;

    $id = absint( $_POST['order_id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'Невірний ID' ] );

    $tOrd  = $wpdb->prefix . 'med_ordering';
    $tMeas = $wpdb->prefix . 'med_measurement';
    $tInd  = $wpdb->prefix . 'med_indicator';

    $order = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$tOrd} WHERE id = %d LIMIT 1", $id )
    );
    if ( ! $order ) wp_send_json_error( [ 'message' => 'Не знайдено' ] );

    // Row-level security: regular users may not view orders that belong to
    // another user, even if they know the ID.
    if ( ! is_admin_user() && (int) $order->id_user !== get_current_user_id() ) {
        wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'med-stat' ) ], 403 );
    }

    $meas = $wpdb->get_results( $wpdb->prepare(
        "SELECT m.id AS meas_id, m.result_value, m.execution_date, m.is_normal,
                i.id AS ind_id, i.name AS ind_name,
                i.min, i.max, i.measure, i.category, i.interpretation_hint
         FROM {$tMeas} m
         INNER JOIN {$tInd} i ON i.id = m.id_indicator
         WHERE m.id_order = %d
         ORDER BY COALESCE(i.category,'я'), i.name",
        $id
    ) );

    wp_send_json_success( [
        'order' => [
            'id'              => absint( $order->id ),
            'order_number'    => $order->order_number,
            'patient_name'    => $order->patient_name,
            'patient_dob'     => $order->patient_dob,
            'collection_date' => $order->collection_date,
            'doctor_name'     => $order->doctor_name,
            'branch_info'     => $order->branch_info,
        ],
        'measurements' => array_map( static fn( $m ) => [
            'meas_id'             => absint( $m->meas_id ),
            'ind_id'              => absint( $m->ind_id ),
            'result_value'        => $m->result_value,
            'execution_date'      => $m->execution_date,
            'is_normal'           => (bool) $m->is_normal,
            'name'                => $m->ind_name,
            'min'                 => isset( $m->min )  ? (float) $m->min  : null,
            'max'                 => isset( $m->max )  ? (float) $m->max  : null,
            'measure'             => $m->measure,
            'category'            => $m->category,
            'interpretation_hint' => $m->interpretation_hint,
        ], $meas ),
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 3 — IMPORT PDF
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_import_pdf', __NAMESPACE__ . '\\ajax_import_pdf' );
function ajax_import_pdf(): void {
    ajax_guard();

    if ( empty( $_FILES['pdf_file']['tmp_name'] ) ) {
        wp_send_json_error( [ 'message' => 'Файл не завантажено.' ] );
    }

    $file  = $_FILES['pdf_file'];
    $finfo = new \finfo( FILEINFO_MIME_TYPE );
    $mime  = $finfo->file( $file['tmp_name'] );
    if ( ! in_array( $mime, [ 'application/pdf', 'application/zip', 'application/octet-stream' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Дозволені лише PDF файли.' ] );
    }

    $upload   = wp_upload_dir();
    $safeName = wp_unique_filename( $upload['path'], sanitize_file_name( $file['name'] ) );
    $tmpPath  = trailingslashit( $upload['path'] ) . $safeName;

    if ( ! move_uploaded_file( $file['tmp_name'], $tmpPath ) ) {
        wp_send_json_error( [ 'message' => 'Не вдалось зберегти файл.' ] );
    }

    try {
        global $wpdb;
        $importer = new ImportCsd( $wpdb );
        $orderId  = $importer->import( $tmpPath );
        @unlink( $tmpPath );
        wp_send_json_success( [ 'message' => __( 'Імпорт успішний.', 'med-stat' ), 'order_id' => absint( $orderId ) ] );
    } catch ( DuplicateOrderException $e ) {
        @unlink( $tmpPath );
        wp_send_json_error( [
            'message'   => esc_html( $e->getMessage() ),
            'duplicate' => true,
            'order_id'  => $e->getOrderId(),
        ] );
    } catch ( \Throwable $e ) {
        @unlink( $tmpPath );
        wp_send_json_error( [ 'message' => esc_html( $e->getMessage() ) ] );
    }
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 4 — DELETE ORDER (with ownership check)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_delete_order', __NAMESPACE__ . '\\ajax_delete_order' );
function ajax_delete_order(): void {
    ajax_guard();
    global $wpdb;

    $id = absint( $_POST['order_id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'Невірний ID' ] );

    $tOrd  = $wpdb->prefix . 'med_ordering';
    $tMeas = $wpdb->prefix . 'med_measurement';

    // Ownership check for non-administrators.
    if ( ! is_admin_user() ) {
        $owner = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id_user FROM {$tOrd} WHERE id = %d LIMIT 1", $id )
        );
        // If the order does not exist or belongs to a different user — deny.
        if ( $owner !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'med-stat' ) ], 403 );
        }
    }

    $wpdb->delete( $tMeas, [ 'id_order' => $id ], [ '%d' ] );
    $wpdb->delete( $tOrd,  [ 'id'       => $id ], [ '%d' ] );

    wp_send_json_success( [ 'message' => 'Замовлення видалено.' ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 5 — EDIT MEASUREMENT + INDICATOR
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_edit_row', __NAMESPACE__ . '\\ajax_edit_row' );
function ajax_edit_row(): void {
    ajax_guard();
    global $wpdb;

    $measId = absint( $_POST['meas_id'] ?? 0 );
    $indId  = absint( $_POST['ind_id']  ?? 0 );
    if ( ! $measId || ! $indId ) wp_send_json_error( [ 'message' => 'Невірні ID' ] );

    $tInd  = $wpdb->prefix . 'med_indicator';
    $tMeas = $wpdb->prefix . 'med_measurement';

    // Update indicator.
    $indData = [];
    foreach ( [ 'name', 'measure', 'category' ] as $f ) {
        if ( isset( $_POST[ $f ] ) ) $indData[ $f ] = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
    }
    foreach ( [ 'min', 'max' ] as $f ) {
        if ( isset( $_POST[ $f ] ) ) $indData[ $f ] = (float) $_POST[ $f ];
    }
    if ( ! empty( $indData ) ) $wpdb->update( $tInd, $indData, [ 'id' => $indId ] );

    // Update measurement.
    if ( isset( $_POST['result_value'] ) ) {
        $rv = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['result_value'] ) ) );
        $wpdb->update( $tMeas, [ 'result_value' => $rv ], [ 'id' => $measId ], [ '%f' ], [ '%d' ] );
    }

    wp_send_json_success( [ 'message' => 'Збережено.' ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 6 — SEARCH INDICATORS (autocomplete)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_search_indicators', __NAMESPACE__ . '\\ajax_search_indicators' );
function ajax_search_indicators(): void {
    ajax_guard();
    global $wpdb;

    $tInd = $wpdb->prefix . 'med_indicator';
    $q    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
    if ( strlen( $q ) < 1 ) wp_send_json_success( [ 'items' => [] ] );

    $like  = '%' . $wpdb->esc_like( $q ) . '%';
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, name, min, max, measure, category, interpretation_hint
         FROM {$tInd} WHERE name LIKE %s ORDER BY name LIMIT 20",
        $like
    ) );

    wp_send_json_success( [ 'items' => $items ?: [] ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 7 — GET SINGLE INDICATOR
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_get_indicator', __NAMESPACE__ . '\\ajax_get_indicator' );
function ajax_get_indicator(): void {
    ajax_guard();
    global $wpdb;

    $tInd = $wpdb->prefix . 'med_indicator';
    $id   = absint( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'Невірний ID' ] );

    $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tInd} WHERE id = %d LIMIT 1", $id ) );
    if ( ! $item ) wp_send_json_error( [ 'message' => 'Не знайдено' ] );

    wp_send_json_success( [ 'indicator' => $item ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 8 — ADD ORDER MANUALLY (with id_user assignment)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_add_order', __NAMESPACE__ . '\\ajax_add_order' );
function ajax_add_order(): void {
    ajax_guard();
    global $wpdb;

    $tOrd  = $wpdb->prefix . 'med_ordering';
    $tMeas = $wpdb->prefix . 'med_measurement';
    $tInd  = $wpdb->prefix . 'med_indicator';

    med_stat_ensure_tables();

    $orderNum = sanitize_text_field( wp_unslash( $_POST['order_number']    ?? '' ) );
    $colDate  = sanitize_text_field( wp_unslash( $_POST['collection_date'] ?? '' ) );
    $doctor   = sanitize_text_field( wp_unslash( $_POST['doctor_name']     ?? '' ) );
    $branch   = sanitize_text_field( wp_unslash( $_POST['branch_info']     ?? '' ) );
    $patient  = sanitize_text_field( wp_unslash( $_POST['patient_name']    ?? '' ) );
    $dob      = sanitize_text_field( wp_unslash( $_POST['patient_dob']     ?? '' ) );

    // Duplicate check.
    if ( $orderNum ) {
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$tOrd} WHERE order_number = %s LIMIT 1",
            $orderNum
        ) );
        if ( $exists ) {
            wp_send_json_error( [
                'message'   => "Замовлення № {$orderNum} вже існує в системі.",
                'duplicate' => true,
                'order_id'  => $exists,
            ] );
        }
    }

    // Normalise dates.
    $colDateDb = null;
    if ( $colDate ) {
        $ts = strtotime( $colDate );
        if ( $ts ) $colDateDb = date( 'Y-m-d H:i:s', $ts );
    }
    $dobDb = null;
    if ( $dob ) {
        $ts = strtotime( $dob );
        if ( $ts ) $dobDb = date( 'Y-m-d', $ts );
    }

    // Insert order — assign id_user to the currently authenticated user.
    $ok = $wpdb->insert(
        $tOrd,
        [
            'order_number'    => $orderNum   ?: null,
            'patient_name'    => $patient    ?: 'Unknown',
            'patient_dob'     => $dobDb,
            'collection_date' => $colDateDb,
            'doctor_name'     => $doctor     ?: null,
            'branch_info'     => $branch     ?: null,
            'id_user'         => get_current_user_id() ?: 1,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
    );

    if ( false === $ok ) {
        wp_send_json_error( [ 'message' => 'Помилка збереження замовлення: ' . $wpdb->last_error ] );
    }
    $orderId = (int) $wpdb->insert_id;

    // Process measurement rows.
    $rows  = is_array( $_POST['rows'] ?? null ) ? $_POST['rows'] : [];
    $saved = 0;

    foreach ( $rows as $r ) {
        $indId     = absint( $r['ind_id'] ?? 0 );
        $resultVal = isset( $r['result_value'] ) ? (float) str_replace( ',', '.', $r['result_value'] ) : null;
        $execDate  = sanitize_text_field( wp_unslash( $r['execution_date'] ?? '' ) );

        $execDateDb = null;
        if ( $execDate ) {
            $ts = strtotime( $execDate );
            if ( $ts ) $execDateDb = date( 'Y-m-d H:i:s', $ts );
        }

        // Resolve or create indicator when only a name is provided.
        if ( ! $indId ) {
            $name = sanitize_text_field( wp_unslash( $r['name'] ?? '' ) );
            if ( ! $name ) continue;

            $indId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tInd} WHERE name = %s LIMIT 1", $name ) );
            if ( ! $indId ) {
                $wpdb->insert( $tInd, [
                    'name'     => $name,
                    'min'      => isset( $r['min'] ) && $r['min'] !== '' ? (float) $r['min'] : null,
                    'max'      => isset( $r['max'] ) && $r['max'] !== '' ? (float) $r['max'] : null,
                    'measure'  => sanitize_text_field( $r['measure']  ?? '' ) ?: null,
                    'category' => sanitize_text_field( $r['category'] ?? '' ) ?: null,
                ], [ '%s', '%f', '%f', '%s', '%s' ] );
                $indId = (int) $wpdb->insert_id;
            }
        }

        if ( ! $indId || $resultVal === null ) continue;

        // Compute is_normal.
        $ind      = $wpdb->get_row( $wpdb->prepare( "SELECT min, max FROM {$tInd} WHERE id = %d", $indId ) );
        $isNormal = 1;
        if ( $ind && $ind->min !== null && $ind->max !== null ) {
            $isNormal = ( $resultVal >= (float) $ind->min && $resultVal <= (float) $ind->max ) ? 1 : 0;
        }

        $wpdb->insert( $tMeas, [
            'id_order'       => $orderId,
            'id_indicator'   => $indId,
            'result_value'   => number_format( $resultVal, 3, '.', '' ),
            'execution_date' => $execDateDb,
            'is_normal'      => $isNormal,
        ], [ '%d', '%d', '%s', '%s', '%d' ] );
        $saved++;
    }

    wp_send_json_success( [
        'message'  => "Збережено замовлення та {$saved} показник(ів).",
        'order_id' => $orderId,
    ] );
}

/* ════════════════════════════════════════════════════════════════════════════
   AJAX 9 — GET USERS LIST (admin-only, for the user filter dropdown)
   ════════════════════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_get_users', __NAMESPACE__ . '\\ajax_get_users' );
function ajax_get_users(): void {
    ajax_guard();

    // Only administrators may retrieve the full user list.
    if ( ! is_admin_user() ) {
        wp_send_json_error( [ 'message' => __( 'Доступ заборонено.', 'med-stat' ) ], 403 );
    }

    global $wpdb;

    // Return only users who actually have orders in the system.
    $t    = $wpdb->prefix . 'med_ordering';
    $rows = $wpdb->get_results(
        "SELECT DISTINCT u.ID AS id, u.display_name AS name
         FROM {$wpdb->users} u
         INNER JOIN {$t} o ON o.id_user = u.ID
         ORDER BY u.display_name ASC"
    );

    wp_send_json_success( [
        'users' => array_map( static fn( $u ) => [
            'id'   => absint( $u->id ),
            'name' => $u->name,
        ], $rows ),
    ] );
}
