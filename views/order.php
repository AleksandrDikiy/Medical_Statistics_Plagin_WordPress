<?php
/**
 * views/order.php — Main orders UI
 *
 * Row-level security is applied both to the initial server-side render and
 * to every subsequent AJAX call that re-fetches orders.  Administrators see
 * all records; regular users see only their own.
 *
 * The user-filter dropdown is rendered exclusively for administrators and is
 * never emitted (not just hidden) for regular users, which prevents any
 * client-side bypass.
 */

declare( strict_types = 1 );

namespace MedicalStatistics;

defined( 'ABSPATH' ) || exit;

med_stat_ensure_tables();

global $wpdb;
$T_ORD  = $wpdb->prefix . 'med_ordering';
$T_MEAS = $wpdb->prefix . 'med_measurement';
$T_IND  = $wpdb->prefix . 'med_indicator';

$date_from    = date( 'Y-m-d', strtotime( '-365 days' ) );
$date_to      = date( 'Y-m-d' );
$current_uid  = get_current_user_id();
$viewer_is_admin = current_user_can( 'manage_options' );

/* ── Row-level security helper for raw SQL in this view ─────────────────── */
// Returns a SQL fragment (already safe) and appends any needed args to $args.
$rls_where = static function ( array &$args ) use ( $viewer_is_admin, $current_uid ): string {
    if ( $viewer_is_admin ) {
        return '';
    }
    $args[] = $current_uid;
    return ' AND id_user = %d';
};

/* ── Initial sidebar data (server-side render) ───────────────────────────── */
$args_sidebar = [ $date_from, $date_to ];
$rls_sidebar  = $rls_where( $args_sidebar );

$sidebar_orders = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, order_number, patient_name, collection_date
         FROM {$T_ORD}
         WHERE DATE(COALESCE(collection_date,created_at)) BETWEEN %s AND %s
         {$rls_sidebar}
         ORDER BY COALESCE(collection_date,created_at) DESC
         LIMIT 10",
        ...$args_sidebar
    )
) ?: [];

$args_count  = [ $date_from, $date_to ];
$rls_count   = $rls_where( $args_count );
$total_orders = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$T_ORD}
         WHERE DATE(COALESCE(collection_date,created_at)) BETWEEN %s AND %s
         {$rls_count}",
        ...$args_count
    )
);

$total_pages = max( 1, (int) ceil( $total_orders / 10 ) );
$first_order = $sidebar_orders[0] ?? null;

/* ── Initial measurement data for the first order ────────────────────────── */
$measurements = [];
if ( $first_order ) {
    // Extra ownership guard even for the initial render.
    $can_view = $viewer_is_admin || ( (int) $first_order->id === $current_uid );
    if ( $can_view || $viewer_is_admin ) {
        $measurements = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id AS meas_id, m.result_value, m.execution_date, m.is_normal,
                        i.id AS ind_id, i.name AS ind_name,
                        i.min, i.max, i.measure, i.category, i.interpretation_hint
                 FROM {$T_MEAS} m
                 INNER JOIN {$T_IND} i ON i.id = m.id_indicator
                 WHERE m.id_order = %d
                 ORDER BY COALESCE(i.category,'я'), i.name",
                absint( $first_order->id )
            )
        ) ?: [];
    }
}

/* ── Admin user list for the filter dropdown ─────────────────────────────── */
$admin_users = [];
if ( $viewer_is_admin ) {
    $admin_users = $wpdb->get_results(
        "SELECT DISTINCT u.ID AS id, u.display_name AS name
         FROM {$wpdb->users} u
         INNER JOIN {$T_ORD} o ON o.id_user = u.ID
         ORDER BY u.display_name ASC"
    ) ?: [];
}

/* ── Template helpers ────────────────────────────────────────────────────── */
function ms_date( ?string $dt ): string {
    if ( ! $dt ) return '—';
    $ts = strtotime( $dt );
    return $ts ? date( 'd.m.Y', $ts ) : $dt;
}
function ms_datetime( ?string $dt ): string {
    if ( ! $dt ) return '—';
    $ts = strtotime( $dt );
    return $ts ? date( 'd.m.Y, H:i', $ts ) : $dt;
}
function ms_num( $n ): string {
    if ( $n === null || $n === '' ) return '—';
    return rtrim( rtrim( number_format( (float) $n, 4, '.', '' ), '0' ), '.' );
}
?>
<div id="med-stat-app" class="med-stat-app">

<!-- САЙДБАР -->
<aside class="ms-sidebar" role="complementary" aria-label="Список замовлень">
  <div class="ms-sidebar__search">
    <div class="ms-search-wrap">
      <svg class="ms-search-icon" width="14" height="14" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="8.5" cy="8.5" r="6.5" stroke="currentColor" stroke-width="1.8"/>
        <path d="M13.5 13.5L18 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      </svg>
      <input id="ms-search" type="search" class="ms-input ms-input--search" placeholder="Пошук…" autocomplete="off">
    </div>
  </div>

  <div class="ms-sidebar__dates">
    <div class="ms-field">
      <label class="ms-label" for="ms-date-from">Від:</label>
      <input id="ms-date-from" type="date" class="ms-input" value="<?php echo esc_attr( $date_from ); ?>" max="<?php echo esc_attr( $date_to ); ?>">
    </div>
    <div class="ms-field">
      <label class="ms-label" for="ms-date-to">До:</label>
      <input id="ms-date-to" type="date" class="ms-input" value="<?php echo esc_attr( $date_to ); ?>" min="<?php echo esc_attr( $date_from ); ?>">
    </div>
  </div>

  <?php if ( $viewer_is_admin && ! empty( $admin_users ) ) : ?>
  <!--
    USER FILTER — administrators only.
    This block is never rendered for regular users; it is not merely hidden.
    The JS loadOrders() call reads #ms-user-filter only when medStat.isAdmin
    is true, providing a second layer of protection.
  -->
  <div class="ms-sidebar__user-filter">
    <div class="ms-field">
      <label class="ms-label" for="ms-user-filter">Користувач:</label>
      <select id="ms-user-filter" class="ms-input ms-input--select">
        <option value="0"><?php echo esc_html__( 'Усі користувачі', 'med-stat' ); ?></option>
        <?php foreach ( $admin_users as $u ) : ?>
          <option value="<?php echo absint( $u->id ); ?>"><?php echo esc_html( $u->name ); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php endif; ?>

  <nav id="ms-order-list" class="ms-order-list" aria-label="Замовлення">
    <?php if ( $sidebar_orders ) : foreach ( $sidebar_orders as $i => $ord ) : ?>
      <button type="button"
        class="ms-order-item <?php echo $i === 0 ? 'ms-order-item--active' : ''; ?>"
        data-order-id="<?php echo absint( $ord->id ); ?>"
        aria-pressed="<?php echo $i === 0 ? 'true' : 'false'; ?>">
        <span class="ms-order-item__num">№ <?php echo esc_html( $ord->order_number ?? '—' ); ?></span>
        <span class="ms-order-item__date"><?php echo esc_html( ms_date( $ord->collection_date ) ); ?></span>
      </button>
    <?php endforeach; else : ?>
      <p class="ms-empty">Немає замовлень у цьому діапазоні.</p>
    <?php endif; ?>
  </nav>

  <div id="ms-pagination" class="ms-pagination <?php echo $total_pages <= 1 ? 'ms-pagination--hidden' : ''; ?>"
       data-current="1" data-total="<?php echo absint( $total_pages ); ?>">
    <button type="button" id="ms-prev-page" class="ms-page-btn" disabled aria-label="Попередня">
      <svg width="7" height="12" viewBox="0 0 7 12" aria-hidden="true"><path d="M6 1L1 6l5 5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>
    </button>
    <span class="ms-page-label"><span id="ms-page-current">1</span> / <span id="ms-page-total"><?php echo absint( $total_pages ); ?></span></span>
    <button type="button" id="ms-next-page" class="ms-page-btn" <?php disabled( $total_pages, 1 ); ?> aria-label="Наступна">
      <svg width="7" height="12" viewBox="0 0 7 12" aria-hidden="true"><path d="M1 1l5 5-5 5" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>
    </button>
  </div>
</aside>

<!-- ГОЛОВНА ПАНЕЛЬ -->
<main id="ms-main" class="ms-main" aria-live="polite">
  <header class="ms-main__header">
    <div id="ms-order-meta" class="ms-order-meta">
      <?php if ( $first_order ) : ?>
        <div class="ms-order-meta__top">
          <h2 class="ms-order-meta__title">Замовлення № <?php echo esc_html( $first_order->order_number ?? '—' ); ?></h2>
          <span class="ms-order-meta__date"><?php echo esc_html( ms_datetime( $first_order->collection_date ) ); ?></span>
        </div>
        <div class="ms-order-meta__details">
          <span>Пацієнт: <?php echo esc_html( $first_order->patient_name ); ?></span>
        </div>
      <?php else : ?>
        <h2 class="ms-order-meta__title">Медична статистика</h2>
        <p class="ms-text-muted">Оберіть замовлення або імпортуйте PDF</p>
      <?php endif; ?>
    </div>
    <div class="ms-main__actions">
      <?php if ( $first_order ) : ?>
        <button type="button" id="ms-delete-btn" class="ms-btn ms-btn--danger"
                data-order-id="<?php echo absint( $first_order->id ); ?>" title="Видалити замовлення">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      <?php endif; ?>
      <button type="button" id="ms-add-btn" class="ms-btn ms-btn--add" title="Додати запис">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Новий запис
      </button>
      <button type="button" id="ms-import-btn" class="ms-btn ms-btn--primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M8 12l4 4 4-4M12 16V4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Імпорт з PDF
      </button>
      <input id="ms-pdf-input" type="file" accept=".pdf,application/pdf" tabindex="-1" aria-hidden="true"
             style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden">
    </div>
  </header>

  <div id="ms-table-wrap" class="ms-table-wrap">
    <?php if ( $measurements ) : ?>
      <div class="ms-table-head" role="row">
        <div class="ms-col ms-col--indicator" role="columnheader">Показник</div>
        <div class="ms-col ms-col--result"    role="columnheader">Результат</div>
        <div class="ms-col ms-col--ref"       role="columnheader">Реф. знач.</div>
        <div class="ms-col ms-col--action"    role="columnheader"></div>
      </div>
      <?php foreach ( $measurements as $row ) :
        $normal  = (bool) $row->is_normal;
        $numDisp = rtrim( rtrim( (string) $row->result_value, '0' ), '.' );
      ?>
      <div class="ms-row <?php echo $normal ? '' : 'ms-row--abnormal'; ?>" role="row"
           data-meas-id="<?php echo absint( $row->meas_id ); ?>"
           data-ind-id="<?php echo absint( $row->ind_id ); ?>">
        <div class="ms-col ms-col--indicator" role="cell">
          <span class="ms-indicator__name"><?php echo esc_html( $row->ind_name ); ?></span>
          <?php if ( $row->category ) : ?><span class="ms-indicator__category"><?php echo esc_html( $row->category ); ?></span><?php endif; ?>
          <?php if ( $row->interpretation_hint ) : ?>
            <span class="ms-indicator__hint"><?php echo esc_html( $row->interpretation_hint ); ?></span>
          <?php endif; ?>
        </div>
        <div class="ms-col ms-col--result" role="cell">
          <span class="ms-value <?php echo $normal ? '' : 'ms-value--abnormal'; ?>"><?php echo esc_html( $numDisp ); ?></span>
          <?php if ( $row->measure ) : ?><span class="ms-unit"><?php echo esc_html( $row->measure ); ?></span><?php endif; ?>
        </div>
        <div class="ms-col ms-col--ref" role="cell">
          <?php if ( $row->min !== null && $row->max !== null ) : ?>
            <span class="ms-ref"><?php echo esc_html( ms_num( $row->min ) . ' – ' . ms_num( $row->max ) ); ?></span>
            <?php if ( $row->measure ) : ?><span class="ms-unit"><?php echo esc_html( $row->measure ); ?></span><?php endif; ?>
          <?php else : ?><span class="ms-text-muted">—</span><?php endif; ?>
        </div>
        <div class="ms-col ms-col--action" role="cell">
          <button type="button" class="ms-edit-btn" aria-label="Редагувати">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php elseif ( $first_order ) : ?>
      <p class="ms-empty ms-empty--main">Показники відсутні.</p>
    <?php else : ?>
      <div class="ms-welcome"><p>Оберіть замовлення або перетягніть PDF-файл із результатами CSD LAB.</p></div>
    <?php endif; ?>
  </div>

  <div id="ms-toast" class="ms-toast" style="display:none" role="alert" aria-live="assertive" aria-atomic="true"></div>
</main>
</div>

<!-- ШАБЛОН РЯДКА -->
<template id="ms-row-tpl">
  <div class="ms-row">
    <div class="ms-col ms-col--indicator">
      <span class="ms-indicator__name"></span>
      <span class="ms-indicator__category"></span>
      <span class="ms-indicator__hint"></span>
    </div>
    <div class="ms-col ms-col--result">
      <span class="ms-value"></span>
      <span class="ms-unit"></span>
    </div>
    <div class="ms-col ms-col--ref">
      <span class="ms-ref"></span>
      <span class="ms-unit"></span>
    </div>
    <div class="ms-col ms-col--action">
      <button type="button" class="ms-edit-btn" aria-label="Редагувати">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
</template>

<!-- ════════════════════════════════════
     MODAL: РЕДАГУВАННЯ ПОКАЗНИКА
     ════════════════════════════════════ -->
<div id="ms-modal" class="ms-modal" style="display:none" role="dialog" aria-modal="true" aria-label="Редагування показника">
  <div class="ms-modal__overlay" id="ms-modal-overlay"></div>
  <div class="ms-modal__box">
    <div class="ms-modal__header">
      <h3 class="ms-modal__title">Редагування показника</h3>
      <button type="button" id="ms-modal-close" class="ms-modal__close" aria-label="Закрити">✕</button>
    </div>
    <div class="ms-modal__body">
      <input type="hidden" id="ms-edit-meas-id">
      <input type="hidden" id="ms-edit-ind-id">
      <div class="ms-modal__grid">
        <div class="ms-form-field ms-form-field--full"><label>Назва</label><input id="ms-edit-name" type="text" class="ms-input"></div>
        <div class="ms-form-field"><label>Одиниця</label><input id="ms-edit-measure" type="text" class="ms-input"></div>
        <div class="ms-form-field"><label>Категорія</label><input id="ms-edit-category" type="text" class="ms-input"></div>
        <div class="ms-form-field"><label>Мін</label><input id="ms-edit-min" type="number" step="0.001" class="ms-input"></div>
        <div class="ms-form-field"><label>Макс</label><input id="ms-edit-max" type="number" step="0.001" class="ms-input"></div>
        <div class="ms-form-field"><label>Результат</label><input id="ms-edit-result" type="number" step="0.001" class="ms-input"></div>
      </div>
    </div>
    <div class="ms-modal__footer">
      <button type="button" id="ms-modal-cancel" class="ms-btn ms-btn--orange">Скасувати</button>
      <button type="button" id="ms-modal-save"   class="ms-btn ms-btn--primary">Зберегти</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════
     MODAL: НОВИЙ ЗАПИС
     ════════════════════════════════════ -->
<div id="ms-add-modal" class="ms-modal ms-modal--wide" style="display:none" role="dialog" aria-modal="true" aria-label="Новий запис">
  <div class="ms-modal__overlay" id="ms-add-modal-overlay"></div>
  <div class="ms-modal__box ms-modal__box--wide">
    <div class="ms-modal__header">
      <h3 class="ms-modal__title">Новий запис</h3>
      <button type="button" id="ms-add-modal-close" class="ms-modal__close" aria-label="Закрити">✕</button>
    </div>
    <div class="ms-modal__body ms-add-body">
      <section class="ms-add-section">
        <h4 class="ms-add-section__title">Замовлення</h4>
        <div class="ms-add-grid ms-add-grid--header">
          <div class="ms-form-field">
            <label for="ms-add-order-num">№ замовлення</label>
            <input id="ms-add-order-num" type="text" class="ms-input" placeholder="CS0000000">
          </div>
          <div class="ms-form-field">
            <label for="ms-add-collection">Дата забору</label>
            <input id="ms-add-collection" type="datetime-local" class="ms-input"
                   value="<?php echo esc_attr( date( 'Y-m-d\TH:i' ) ); ?>">
          </div>
          <div class="ms-form-field">
            <label for="ms-add-doctor">Лікар</label>
            <input id="ms-add-doctor" type="text" class="ms-input" placeholder="ПІБ лікаря">
          </div>
          <div class="ms-form-field ms-form-field--full">
            <label for="ms-add-branch">Забірний пункт</label>
            <input id="ms-add-branch" type="text" class="ms-input" placeholder="Назва та адреса">
          </div>
          <div class="ms-form-field">
            <label for="ms-add-patient">Пацієнт</label>
            <input id="ms-add-patient" type="text" class="ms-input">
          </div>
          <div class="ms-form-field">
            <label for="ms-add-dob">Дата народження</label>
            <input id="ms-add-dob" type="date" class="ms-input">
          </div>
        </div>
      </section>

      <section class="ms-add-section">
        <div class="ms-add-section__head">
          <h4 class="ms-add-section__title">Показники</h4>
          <button type="button" id="ms-add-row-btn" class="ms-btn ms-btn--sm ms-btn--outline">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            Додати рядок
          </button>
        </div>
        <div class="ms-meas-table-wrap">
          <div class="ms-meas-table-head">
            <span>Назва показника</span><span>Мін</span><span>Макс</span>
            <span>Одиниця</span><span>Результат</span><span>Дата вик.</span><span></span>
          </div>
          <div id="ms-meas-rows"><!-- рядки додаються JS --></div>
        </div>
      </section>
    </div>
    <div class="ms-modal__footer">
      <button type="button" id="ms-add-modal-cancel" class="ms-btn ms-btn--orange">Скасувати</button>
      <button type="button" id="ms-add-modal-save"   class="ms-btn ms-btn--primary">Зберегти запис</button>
    </div>
  </div>
</div>

<!-- ШАБЛОН РЯДКА ПОКАЗНИКА У "НОВИЙ ЗАПИС" -->
<template id="ms-meas-row-tpl">
  <div class="ms-meas-row">
    <div class="ms-meas-cell ms-meas-cell--name">
      <input type="text" class="ms-input ms-ind-search" placeholder="Назва…" autocomplete="off">
      <div class="ms-ind-dropdown" style="display:none"></div>
      <input type="hidden" class="ms-ind-id">
    </div>
    <div class="ms-meas-cell"><input type="number" step="0.001" class="ms-input ms-ind-min" readonly tabindex="-1"></div>
    <div class="ms-meas-cell"><input type="number" step="0.001" class="ms-input ms-ind-max" readonly tabindex="-1"></div>
    <div class="ms-meas-cell"><input type="text" class="ms-input ms-ind-measure" readonly tabindex="-1"></div>
    <div class="ms-meas-cell"><input type="number" step="0.001" class="ms-input ms-meas-value" placeholder="0.00"></div>
    <div class="ms-meas-cell">
      <input type="datetime-local" class="ms-input ms-meas-date" value="<?php echo esc_attr( date( 'Y-m-d\TH:i' ) ); ?>">
    </div>
    <div class="ms-meas-cell ms-meas-cell--del">
      <button type="button" class="ms-row-del-btn" aria-label="Видалити рядок">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
</template>
