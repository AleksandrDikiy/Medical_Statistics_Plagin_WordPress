<?php
/**
 * Plugin Name:  Medical Statistics
 * Plugin URI:   #
 * Description:  Трекер медичних показників — імпорт PDF CSD LAB, зберігання, візуалізація, аналітика.
 * Version:      1.5.4
 * Author:       DAV
 * Text Domain:  med-stat
 */

namespace MedicalStatistics;

defined('ABSPATH') || exit;

define('MED_STAT_VERSION', '1.5.4');
define('MED_STAT_DIR',     plugin_dir_path(__FILE__));
define('MED_STAT_URL',     plugin_dir_url(__FILE__));

require_once MED_STAT_DIR . 'includes/db-setup.php';
require_once MED_STAT_DIR . 'includes/class-pdf-parser.php';
require_once MED_STAT_DIR . 'includes/class-import-csd.php';

/* ── Активація ── */
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');
function activate(): void {
    med_stat_create_tables();
    med_stat_add_role();
    flush_rewrite_rules();
}

function med_stat_add_role(): void {
    if (!get_role('medical_statistics')) {
        add_role('medical_statistics', __('Medical Statistics','med-stat'), ['read'=>true,'medical_statistics'=>true]);
    }
}

/* ── Admin notice якщо таблиць нема ── */
add_action('admin_notices', __NAMESPACE__ . '\\admin_notice_tables');
function admin_notice_tables(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix.'med_ordering'))) return;
    $url = wp_nonce_url(admin_url('admin-post.php?action=med_stat_create_tables'), 'med_stat_create_tables');
    echo '<div class="notice notice-error"><p><strong>Medical Statistics:</strong> Таблиці БД не створено. <a href="'.esc_url($url).'" class="button button-primary" style="margin-left:10px">Створити зараз</a></p></div>';
}
add_action('admin_post_med_stat_create_tables', __NAMESPACE__ . '\\handle_create_tables');
function handle_create_tables(): void {
    check_admin_referer('med_stat_create_tables');
    if (!current_user_can('manage_options')) wp_die();
    delete_option('med_stat_db_version');
    med_stat_create_tables();
    med_stat_add_role();
    wp_redirect(admin_url('plugins.php?med_stat_tables=created'));
    exit;
}
add_action('admin_notices', __NAMESPACE__ . '\\admin_notice_created');
function admin_notice_created(): void {
    if (isset($_GET['med_stat_tables']) && $_GET['med_stat_tables']==='created')
        echo '<div class="notice notice-success is-dismissible"><p>Medical Statistics: таблиці створено.</p></div>';
}

/* ── Активи ── */
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets');
function enqueue_assets(): void {
    if (!current_user_can('medical_statistics')) return;
    $vCss = MED_STAT_VERSION.'.'.filemtime(MED_STAT_DIR.'css/med_stat.css');
    $vJs  = MED_STAT_VERSION.'.'.filemtime(MED_STAT_DIR.'js/med_stat.js');
    wp_enqueue_style('med-stat', MED_STAT_URL.'css/med_stat.css', [], $vCss);
    wp_enqueue_script('med-stat', MED_STAT_URL.'js/med_stat.js', ['jquery'], $vJs, true);
    wp_localize_script('med-stat', 'medStat', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('med_stat_nonce'),
        'i18n'    => [
            'loading'     => __('Завантаження…','med-stat'),
            'importOk'    => __('Імпорт успішний.','med-stat'),
            'importFail'  => __('Помилка імпорту.','med-stat'),
            'noResults'   => __('Нічого не знайдено.','med-stat'),
            'orderLabel'  => __('Замовлення №','med-stat'),
            'deleteConfirm'=> __('Видалити це замовлення та всі його результати?','med-stat'),
            'deleteOk'    => __('Замовлення видалено.','med-stat'),
            'saveOk'      => __('Збережено.','med-stat'),
        ],
    ]);
}

/* ── Шорткод ── */
add_shortcode('medical_statistics', __NAMESPACE__ . '\\render_shortcode');
function render_shortcode(): string {
    if (!current_user_can('medical_statistics'))
        return '<p class="med-stat-denied">'.esc_html__('Доступ заборонено.','med-stat').'</p>';
    ob_start();
    require MED_STAT_DIR.'views/order.php';
    return (string)ob_get_clean();
}

/* ════════════════════════════════════════════════════════════
   SHORTCODE: [medical_analit] — Аналітика показника
   ════════════════════════════════════════════════════════════ */
add_shortcode( 'medical_analit', __NAMESPACE__ . '\\render_analit_shortcode' );
function render_analit_shortcode(): string {
    if ( ! current_user_can( 'medical_statistics' ) )
        return '<p class="med-stat-denied">' . esc_html__( 'Доступ заборонено.', 'med-stat' ) . '</p>';
    ob_start();
    require MED_STAT_DIR . 'views/med_analit.php';
    return (string) ob_get_clean();
}

/* Підключення активів для [medical_analit] */
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_analit_assets' );
function enqueue_analit_assets(): void {
    if ( ! current_user_can( 'medical_statistics' ) ) return;

    $vCss = MED_STAT_VERSION . '.' . (file_exists( MED_STAT_DIR . 'css/med_analit.css' ) ? filemtime( MED_STAT_DIR . 'css/med_analit.css' ) : '1');
    $vJs  = MED_STAT_VERSION . '.' . (file_exists( MED_STAT_DIR . 'js/med_analit.js' )  ? filemtime( MED_STAT_DIR . 'js/med_analit.js'  ) : '1');

    wp_enqueue_style( 'med-analit', MED_STAT_URL . 'css/med_analit.css', [], $vCss );

    // Chart.js v4 (CDN)
    wp_enqueue_script(
        'chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
        [], '4.4.3', true
    );
    // chartjs-plugin-annotation v3
    wp_enqueue_script(
        'chartjs-annotation',
        'https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js',
        ['chartjs'], '3.0.1', true
    );
    wp_enqueue_script( 'med-analit', MED_STAT_URL . 'js/med_analit.js', ['jquery', 'chartjs', 'chartjs-annotation'], $vJs, true );

    wp_localize_script( 'med-analit', 'medAnalit', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'med_stat_nonce' ),
        'i18n'    => [
            'loading'  => __( 'Завантаження…', 'med-stat' ),
            'noData'   => __( 'Немає даних для цього показника в обраному діапазоні.', 'med-stat' ),
            'errorNet' => __( 'Помилка мережі. Спробуйте ще раз.', 'med-stat' ),
            'selectInd'=> __( 'Оберіть показник у фільтрі вище', 'med-stat' ),
        ],
    ]);
}

/* ════════════════════════════════════════════════════════════
   AJAX: Дані для графіку аналітики
   ════════════════════════════════════════════════════════════ */
add_action( 'wp_ajax_med_stat_chart_data', __NAMESPACE__ . '\\ajax_chart_data' );
function ajax_chart_data(): void {
    ajax_guard();
    global $wpdb;
    $tInd  = $wpdb->prefix . 'med_indicator';
    $tMeas = $wpdb->prefix . 'med_measurement';

    $indId    = absint( $_POST['ind_id']    ?? 0 );
    $dateFrom = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
    $dateTo   = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

    if ( ! $indId ) wp_send_json_error( ['message' => __( 'Оберіть показник.', 'med-stat' )] );

    // Нормалізуємо дати
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
        wp_send_json_error( ['message' => __( 'Немає даних для цього показника в обраному діапазоні.', 'med-stat' ), 'empty' => true] );
    }

    $meta = $rows[0]; // name, min, max, measure однакові для всього показника

    $labels = [];
    $values = [];
    foreach ( $rows as $row ) {
        // Форматуємо дату для X-осі
        $ts = $row->execution_date ? strtotime( $row->execution_date ) : 0;
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


/* ── AJAX guard ── */
function ajax_guard(): void {
    check_ajax_referer('med_stat_nonce','nonce');
    if (!current_user_can('medical_statistics'))
        wp_send_json_error(['message'=>__('Доступ заборонено.','med-stat')], 403);
}

/* ══════════════════════════════════════════════════════════
   AJAX 1 — GET ORDERS LIST
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_get_orders', __NAMESPACE__ . '\\ajax_get_orders');
function ajax_get_orders(): void {
    ajax_guard();
    global $wpdb;
    $page     = max(1, absint($_POST['page'] ?? 1));
    $per      = 10;
    $offset   = ($page-1)*$per;
    $from     = sanitize_text_field(wp_unslash($_POST['date_from'] ?? ''));
    $to       = sanitize_text_field(wp_unslash($_POST['date_to']   ?? ''));
    // Якщо дати порожні — показуємо всі записи за останній рік
    if (!$from) $from = date('Y-m-d', strtotime('-365 days'));
    if (!$to)   $to   = date('Y-m-d');
    $search   = sanitize_text_field(wp_unslash($_POST['search']    ?? ''));
    $t        = $wpdb->prefix.'med_ordering';
    $wheres   = ['1=1']; $args = [];

    if ($from) { $wheres[] = 'DATE(COALESCE(collection_date,created_at))>=%s'; $args[]=$from; }
    if ($to)   { $wheres[] = 'DATE(COALESCE(collection_date,created_at))<=%s'; $args[]=$to;   }
    if ($search) {
        $like = '%'.$wpdb->esc_like($search).'%';
        $wheres[] = '(order_number LIKE %s OR patient_name LIKE %s)';
        $args[] = $like; $args[] = $like;
    }
    $where = implode(' AND ',$wheres);
    $total  = (int)($args ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$where}",...$args)) : $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}"));
    $dArgs  = array_merge($args, [$per,$offset]);
    $orders = $wpdb->get_results($wpdb->prepare("SELECT id,order_number,patient_name,collection_date,created_at FROM {$t} WHERE {$where} ORDER BY COALESCE(collection_date,created_at) DESC LIMIT %d OFFSET %d",...$dArgs));

    wp_send_json_success([
        'orders'       => array_map(fn($o)=>[
            'id'              => absint($o->id),
            'order_number'    => $o->order_number,
            'patient_name'    => $o->patient_name,
            'collection_date' => $o->collection_date,
        ], $orders),
        'total'        => $total,
        'pages'        => (int)ceil($total/$per),
        'current_page' => $page,
    ]);
}

/* ══════════════════════════════════════════════════════════
   AJAX 2 — GET ORDER DETAIL
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_get_order', __NAMESPACE__ . '\\ajax_get_order');
function ajax_get_order(): void {
    ajax_guard();
    global $wpdb;
    $id = absint($_POST['order_id'] ?? 0);
    if (!$id) wp_send_json_error(['message'=>'Невірний ID']);

    $tOrd  = $wpdb->prefix.'med_ordering';
    $tMeas = $wpdb->prefix.'med_measurement';
    $tInd  = $wpdb->prefix.'med_indicator';

    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tOrd} WHERE id=%d LIMIT 1",$id));
    if (!$order) wp_send_json_error(['message'=>'Не знайдено']);

    $meas = $wpdb->get_results($wpdb->prepare(
        "SELECT m.id AS meas_id, m.result_value, m.execution_date, m.is_normal,
                i.id AS ind_id, i.name AS ind_name,
                i.min, i.max, i.measure, i.category, i.interpretation_hint
         FROM {$tMeas} m
         INNER JOIN {$tInd} i ON i.id=m.id_indicator
         WHERE m.id_order=%d ORDER BY COALESCE(i.category,'я'),i.name", $id));

    wp_send_json_success([
        'order' => [
            'id'              => absint($order->id),
            'order_number'    => $order->order_number,
            'patient_name'    => $order->patient_name,
            'patient_dob'     => $order->patient_dob,
            'collection_date' => $order->collection_date,
            'doctor_name'     => $order->doctor_name,
            'branch_info'     => $order->branch_info,
        ],
        'measurements' => array_map(fn($m)=>[
            'meas_id'            => absint($m->meas_id),
            'ind_id'             => absint($m->ind_id),
            'result_value'       => $m->result_value,   // числове, без esc_html
            'execution_date'     => $m->execution_date,
            'is_normal'          => (bool)$m->is_normal,
            'name'               => $m->ind_name,

            'min'                => isset($m->min)  ? (float)$m->min  : null,
            'max'                => isset($m->max)  ? (float)$m->max  : null,
            'measure'            => $m->measure,
            'category'           => $m->category,
            'interpretation_hint'=> $m->interpretation_hint,
        ], $meas),
    ]);
}

/* ══════════════════════════════════════════════════════════
   AJAX 3 — IMPORT PDF
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_import_pdf', __NAMESPACE__ . '\\ajax_import_pdf');
function ajax_import_pdf(): void {
    ajax_guard();
    if (empty($_FILES['pdf_file']['tmp_name'])) wp_send_json_error(['message'=>'Файл не завантажено.']);

    $file  = $_FILES['pdf_file'];
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['application/pdf','application/zip','application/octet-stream'], true))
        wp_send_json_error(['message'=>'Дозволені лише PDF файли.']);

    $upload   = wp_upload_dir();
    $safeName = wp_unique_filename($upload['path'], sanitize_file_name($file['name']));
    $tmpPath  = trailingslashit($upload['path']).$safeName;
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) wp_send_json_error(['message'=>'Не вдалось зберегти файл.']);

    try {
        global $wpdb;
        $importer = new ImportCsd($wpdb);
        $orderId  = $importer->import($tmpPath);
        @unlink($tmpPath);
        wp_send_json_success(['message'=>__('Імпорт успішний.','med-stat'), 'order_id'=>absint($orderId)]);
    } catch (DuplicateOrderException $e) {
        @unlink($tmpPath);
        // Повертаємо success=false але з order_id щоб JS міг відкрити існуюче замовлення
        wp_send_json_error([
            'message'    => esc_html($e->getMessage()),
            'duplicate'  => true,
            'order_id'   => $e->getOrderId(),
        ]);
    } catch (\Throwable $e) {
        @unlink($tmpPath);
        wp_send_json_error(['message'=>esc_html($e->getMessage())]);
    }
}

/* ══════════════════════════════════════════════════════════
   AJAX 4 — DELETE ORDER
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_delete_order', __NAMESPACE__ . '\\ajax_delete_order');
function ajax_delete_order(): void {
    ajax_guard();
    global $wpdb;
    $id = absint($_POST['order_id'] ?? 0);
    if (!$id) wp_send_json_error(['message'=>'Невірний ID']);

    $tOrd  = $wpdb->prefix.'med_ordering';
    $tMeas = $wpdb->prefix.'med_measurement';

    $wpdb->delete($tMeas, ['id_order'=>$id], ['%d']);
    $wpdb->delete($tOrd,  ['id'=>$id],        ['%d']);

    wp_send_json_success(['message'=>'Замовлення видалено.']);
}

/* ══════════════════════════════════════════════════════════
   AJAX 5 — EDIT MEASUREMENT + INDICATOR
   ══════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_edit_row', __NAMESPACE__ . '\\ajax_edit_row');
function ajax_edit_row(): void {
    ajax_guard();
    global $wpdb;

    $measId = absint($_POST['meas_id'] ?? 0);
    $indId  = absint($_POST['ind_id']  ?? 0);
    if (!$measId || !$indId) wp_send_json_error(['message'=>'Невірні ID']);

    $tInd  = $wpdb->prefix.'med_indicator';
    $tMeas = $wpdb->prefix.'med_measurement';

    // Оновлення ind
    $indData = [];
    foreach (['name','measure','category'] as $f) {
        if (isset($_POST[$f])) $indData[$f] = sanitize_text_field(wp_unslash($_POST[$f]));
    }
    foreach (['min','max'] as $f) {
        if (isset($_POST[$f])) $indData[$f] = (float)$_POST[$f];
    }
    if (!empty($indData)) $wpdb->update($tInd, $indData, ['id'=>$indId]);

    // Оновлення meas
    if (isset($_POST['result_value'])) {
        $rv = (float)str_replace(',','.',sanitize_text_field(wp_unslash($_POST['result_value'])));
        $wpdb->update($tMeas, ['result_value'=>$rv], ['id'=>$measId], ['%f'], ['%d']);
    }

    wp_send_json_success(['message'=>'Збережено.']);
}

/* ════════════════════════════════════════════════════════
   AJAX: Пошук показників (autocomplete)
   ════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_search_indicators', __NAMESPACE__ . '\\ajax_search_indicators');
function ajax_search_indicators(): void {
    ajax_guard();
    global $wpdb;
    $tInd = $wpdb->prefix.'med_indicator';
    $q    = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
    if (strlen($q) < 1) wp_send_json_success(['items' => []]);

    $like  = '%' . $wpdb->esc_like($q) . '%';
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, min, max, measure, category, interpretation_hint
         FROM {$tInd} WHERE name LIKE %s ORDER BY name LIMIT 20",
        $like
    ));
    wp_send_json_success(['items' => $items ?: []]);
}

/* ════════════════════════════════════════════════════════
   AJAX: Отримати один показник
   ════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_get_indicator', __NAMESPACE__ . '\\ajax_get_indicator');
function ajax_get_indicator(): void {
    ajax_guard();
    global $wpdb;
    $tInd = $wpdb->prefix.'med_indicator';
    $id   = absint($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['message' => 'Невірний ID']);

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tInd} WHERE id=%d LIMIT 1", $id));
    if (!$item) wp_send_json_error(['message' => 'Не знайдено']);
    wp_send_json_success(['indicator' => $item]);
}

/* ════════════════════════════════════════════════════════
   AJAX: Зберегти новий запис (замовлення + показники)
   ════════════════════════════════════════════════════════ */
add_action('wp_ajax_med_stat_add_order', __NAMESPACE__ . '\\ajax_add_order');
function ajax_add_order(): void {
    ajax_guard();
    global $wpdb;
    $tOrd  = $wpdb->prefix.'med_ordering';
    $tMeas = $wpdb->prefix.'med_measurement';
    $tInd  = $wpdb->prefix.'med_indicator';

    med_stat_ensure_tables();

    // Хедер замовлення
    $orderNum   = sanitize_text_field(wp_unslash($_POST['order_number']    ?? ''));
    $colDate    = sanitize_text_field(wp_unslash($_POST['collection_date'] ?? ''));
    $doctor     = sanitize_text_field(wp_unslash($_POST['doctor_name']     ?? ''));
    $branch     = sanitize_text_field(wp_unslash($_POST['branch_info']     ?? ''));
    $patient    = sanitize_text_field(wp_unslash($_POST['patient_name']    ?? ''));
    $dob        = sanitize_text_field(wp_unslash($_POST['patient_dob']     ?? ''));

    // Перевірка дублювання
    if ($orderNum) {
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tOrd} WHERE order_number=%s LIMIT 1", $orderNum
        ));
        if ($exists) {
            wp_send_json_error(['message' => "Замовлення № {$orderNum} вже існує в системі.", 'duplicate' => true, 'order_id' => $exists]);
        }
    }

    // Нормалізація дати
    $colDateDb = null;
    if ($colDate) {
        $ts = strtotime($colDate);
        if ($ts) $colDateDb = date('Y-m-d H:i:s', $ts);
    }
    $dobDb = null;
    if ($dob) {
        $ts = strtotime($dob);
        if ($ts) $dobDb = date('Y-m-d', $ts);
    }

    // Вставляємо замовлення
    $ok = $wpdb->insert($tOrd, [
        'order_number'    => $orderNum ?: null,
        'patient_name'    => $patient  ?: 'Unknown',
        'patient_dob'     => $dobDb,
        'collection_date' => $colDateDb,
        'doctor_name'     => $doctor   ?: null,
        'branch_info'     => $branch   ?: null,
    ], ['%s','%s','%s','%s','%s','%s']);

    if (false === $ok) {
        wp_send_json_error(['message' => 'Помилка збереження замовлення: '.$wpdb->last_error]);
    }
    $orderId = (int)$wpdb->insert_id;

    // Рядки показників
    $rows = $_POST['rows'] ?? [];
    if (!is_array($rows)) $rows = [];

    $saved = 0;
    foreach ($rows as $r) {
        $indId      = absint($r['ind_id'] ?? 0);
        $resultVal  = isset($r['result_value']) ? (float)str_replace(',', '.', $r['result_value']) : null;
        $execDate   = sanitize_text_field(wp_unslash($r['execution_date'] ?? ''));
        $execDateDb = null;
        if ($execDate) {
            $ts = strtotime($execDate);
            if ($ts) $execDateDb = date('Y-m-d H:i:s', $ts);
        }

        // Якщо ind_id не вказано але є назва — знаходимо або створюємо показник
        if (!$indId) {
            $name = sanitize_text_field(wp_unslash($r['name'] ?? ''));
            if (!$name) continue;
            $indId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tInd} WHERE name=%s LIMIT 1", $name));
            if (!$indId) {
                $wpdb->insert($tInd, [
                    'name'     => $name,
                    'min'      => isset($r['min']) && $r['min'] !== '' ? (float)$r['min'] : null,
                    'max'      => isset($r['max']) && $r['max'] !== '' ? (float)$r['max'] : null,
                    'measure'  => sanitize_text_field($r['measure'] ?? '') ?: null,
                    'category' => sanitize_text_field($r['category'] ?? '') ?: null,
                ], ['%s','%f','%f','%s','%s']);
                $indId = (int)$wpdb->insert_id;
            }
        }
        if (!$indId || $resultVal === null) continue;

        // is_normal
        $ind = $wpdb->get_row($wpdb->prepare("SELECT min,max FROM {$tInd} WHERE id=%d", $indId));
        $isNormal = 1;
        if ($ind && $ind->min !== null && $ind->max !== null) {
            $isNormal = ($resultVal >= (float)$ind->min && $resultVal <= (float)$ind->max) ? 1 : 0;
        }

        $wpdb->insert($tMeas, [
            'id_order'       => $orderId,
            'id_indicator'   => $indId,
            'result_value'   => number_format($resultVal, 3, '.', ''),
            'execution_date' => $execDateDb,
            'is_normal'      => $isNormal,
        ], ['%d','%d','%s','%s','%d']);
        $saved++;
    }

    wp_send_json_success([
        'message'   => "Збережено замовлення та {$saved} показник(ів).",
        'order_id'  => $orderId,
    ]);
}
