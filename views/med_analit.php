<?php
/**
 * views/med_analit.php — Аналітика показника [medical_analit]
 */
namespace MedicalStatistics;
defined( 'ABSPATH' ) || exit;

global $wpdb;
$tInd = $wpdb->prefix . 'med_indicator';

/* Список показників для dropdown */
$indicators = $wpdb->get_results(
    "SELECT id AS id_indicator, name FROM {$tInd} ORDER BY name ASC"
) ?: [];

/* Поточний рік як діапазон за замовчуванням */
$default_from = date( 'Y' ) . '-01-01';
$default_to   = date( 'Y' ) . '-12-31';
?>
<div class="med-analit-app" id="ma-app">

  <!-- ── Фільтр-панель ── -->
  <div class="ma-filter-bar" role="search" aria-label="Фільтр аналітики">

    <span class="ma-filter-label">Вибір показника:</span>

    <select id="ma-indicator-select" class="ma-select" aria-label="Показник">
      <option value="">— Оберіть показник —</option>
      <?php foreach ( $indicators as $ind ) : ?>
        <option value="<?php echo absint( $ind->id_indicator ); ?>">
          <?php echo esc_html( $ind->name ); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <div class="ma-filter-sep" aria-hidden="true"></div>

    <span class="ma-filter-label">Початок:</span>
    <input type="date" id="ma-date-from" class="ma-input-date"
           value="<?php echo esc_attr( $default_from ); ?>"
           aria-label="Початок діапазону">

    <span class="ma-filter-label">Закінчення:</span>
    <input type="date" id="ma-date-to" class="ma-input-date"
           value="<?php echo esc_attr( $default_to ); ?>"
           aria-label="Кінець діапазону">

  </div><!-- /.ma-filter-bar -->

  <!-- ── Графік ── -->
  <div class="ma-chart-wrap" id="ma-chart-wrap">

    <p class="ma-chart-title" id="ma-chart-title">Оберіть показник для відображення динаміки</p>

    <div id="ma-state" class="ma-state">
      <span class="ma-text-sub">Оберіть показник у фільтрі вище</span>
    </div>

    <div id="ma-canvas-wrap" class="ma-chart-canvas-wrap" style="display:none">
      <canvas id="ma-chart" aria-label="Графік показника" role="img"></canvas>
    </div>

    <div id="ma-error" class="ma-error" style="display:none" role="alert"></div>

    <div class="ma-legend" id="ma-legend" style="display:none">
      <div class="ma-legend-item">
        <span class="ma-legend-dot ma-legend-dot--line"></span>
        <span id="ma-legend-name">Значення</span>
      </div>
      <div class="ma-legend-item">
        <span class="ma-legend-dot ma-legend-dot--min"></span>
        <span>Мін (норма)</span>
      </div>
      <div class="ma-legend-item">
        <span class="ma-legend-dot ma-legend-dot--max"></span>
        <span>Макс (норма)</span>
      </div>
    </div>

  </div><!-- /.ma-chart-wrap -->

</div><!-- /.med-analit-app -->
