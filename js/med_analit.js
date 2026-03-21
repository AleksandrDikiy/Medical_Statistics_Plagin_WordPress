/**
 * med_analit.js — Аналітика медичних показників
 * Chart.js 4 + chartjs-plugin-annotation
 */
/* global medAnalit, Chart */
( function () {
    'use strict';

    /* ── Стан ── */
    var chartInstance = null;
    var debounceTimer = null;

    /* ── DOM ── */
    function $( id ) { return document.getElementById( id ); }
    var selectEl   = $( 'ma-indicator-select' );
    var dateFromEl = $( 'ma-date-from' );
    var dateToEl   = $( 'ma-date-to' );
    var titleEl    = $( 'ma-chart-title' );
    var stateEl    = $( 'ma-state' );
    var canvasWrap = $( 'ma-canvas-wrap' );
    var canvasEl   = $( 'ma-chart' );
    var errorEl    = $( 'ma-error' );
    var legendEl   = $( 'ma-legend' );
    var legendName = $( 'ma-legend-name' );

    if ( ! selectEl ) return; // shortcode не на сторінці

    /* ── Утиліти ── */
    function showState( html ) {
        if ( stateEl )   { stateEl.innerHTML = html; stateEl.style.display = 'flex'; }
        if ( canvasWrap ) canvasWrap.style.display = 'none';
        if ( errorEl )    errorEl.style.display = 'none';
        if ( legendEl )   legendEl.style.display = 'none';
    }

    function showError( msg ) {
        if ( errorEl ) { errorEl.textContent = msg; errorEl.style.display = 'block'; }
        if ( stateEl )    stateEl.style.display = 'none';
        if ( canvasWrap ) canvasWrap.style.display = 'none';
        if ( legendEl )   legendEl.style.display = 'none';
    }

    function showChart() {
        if ( stateEl )    stateEl.style.display = 'none';
        if ( canvasWrap ) canvasWrap.style.display = 'block';
        if ( errorEl )    errorEl.style.display = 'none';
        if ( legendEl )   legendEl.style.display = 'flex';
    }

    function spinnerHtml( txt ) {
        return '<span class="ma-spinner" aria-hidden="true"></span><span>' + ( txt || '' ) + '</span>';
    }

    /* ── Побудова графіку ── */
    function buildChart( data ) {
        if ( chartInstance ) {
            chartInstance.destroy();
            chartInstance = null;
        }

        var refMin    = data.min;
        var refMax    = data.max;
        var measure   = data.measure ? '(' + data.measure + ')' : '';
        var hasNorms  = refMin !== null && refMax !== null;

        /* Annotation: горизонтальні лінії min/max */
        var annotations = {};
        if ( hasNorms ) {
            annotations.lineMin = {
                type:        'line',
                yMin:        refMin,
                yMax:        refMin,
                borderColor: 'rgba(34,197,94,.8)',
                borderWidth: 2,
                borderDash:  [6, 4],
                label: {
                    display:         true,
                    content:         'Min: ' + refMin,
                    position:        'start',
                    color:           '#16a34a',
                    font:            { size: 11 },
                    backgroundColor: 'transparent',
                    yAdjust:         -8,
                },
            };
            annotations.lineMax = {
                type:        'line',
                yMin:        refMax,
                yMax:        refMax,
                borderColor: 'rgba(239,68,68,.8)',
                borderWidth: 2,
                borderDash:  [6, 4],
                label: {
                    display:         true,
                    content:         'Max: ' + refMax,
                    position:        'start',
                    color:           '#dc2626',
                    font:            { size: 11 },
                    backgroundColor: 'transparent',
                    yAdjust:         -8,
                },
            };
            /* Залита зона норми */
            annotations.normBox = {
                type:              'box',
                yMin:              refMin,
                yMax:              refMax,
                backgroundColor:   'rgba(34,197,94,.06)',
                borderWidth:       0,
            };
        }

        /* Кольори точок: червоний якщо поза нормою */
        var pointColors = data.values.map( function ( v ) {
            if ( ! hasNorms ) return '#3b82f6';
            return ( v >= refMin && v <= refMax ) ? '#3b82f6' : '#ef4444';
        } );

        chartInstance = new Chart( canvasEl, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [ {
                    label:            data.name + ' ' + measure,
                    data:             data.values,
                    borderColor:      '#3b82f6',
                    backgroundColor:  'rgba(59,130,246,.08)',
                    borderWidth:      2.5,
                    pointBackgroundColor: pointColors,
                    pointBorderColor:     pointColors,
                    pointRadius:      5,
                    pointHoverRadius: 7,
                    tension:          0.3,
                    fill:             false,
                } ],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function ( ctx ) {
                                var val = ctx.parsed.y;
                                var suffix = data.measure ? ' ' + data.measure : '';
                                var norm   = '';
                                if ( hasNorms ) {
                                    norm = ( val >= refMin && val <= refMax ) ? ' ✓' : ' ⚠ поза нормою';
                                }
                                return data.name + ': ' + val + suffix + norm;
                            },
                        },
                        backgroundColor: 'rgba(15,23,42,.85)',
                        titleFont:    { size: 12 },
                        bodyFont:     { size: 12 },
                        padding:      10,
                        cornerRadius: 8,
                    },
                    annotation: { annotations: annotations },
                },
                scales: {
                    x: {
                        title: {
                            display:    true,
                            text:       'Дата результату',
                            font:       { size: 11 },
                            color:      '#6b7280',
                            padding:    { top: 6 },
                        },
                        grid:  { color: 'rgba(0,0,0,.05)' },
                        ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 45 },
                    },
                    y: {
                        title: {
                            display:  true,
                            text:     'Значення показника' + ( data.measure ? ' (' + data.measure + ')' : '' ),
                            font:     { size: 11 },
                            color:    '#6b7280',
                            padding:  { bottom: 6 },
                        },
                        grid:  { color: 'rgba(0,0,0,.05)' },
                        ticks: { color: '#6b7280', font: { size: 11 } },
                        grace: '10%',
                    },
                },
            },
        } );
    }

    /* ── Завантаження даних ── */
    function loadChartData() {
        var indId    = selectEl.value;
        var dateFrom = dateFromEl ? dateFromEl.value : '';
        var dateTo   = dateToEl   ? dateToEl.value   : '';

        if ( ! indId ) {
            showState( '<span>' + medAnalit.i18n.selectInd + '</span>' );
            if ( titleEl ) titleEl.textContent = 'Оберіть показник для відображення динаміки';
            return;
        }

        showState( spinnerHtml( medAnalit.i18n.loading ) );

        window.jQuery.post( medAnalit.ajaxUrl, {
            action:    'med_stat_chart_data',
            nonce:     medAnalit.nonce,
            ind_id:    indId,
            date_from: dateFrom,
            date_to:   dateTo,
        } )
        .done( function ( res ) {
            if ( ! res.success ) {
                if ( res.data && res.data.empty ) {
                    showState( '<span>' + ( res.data.message || medAnalit.i18n.noData ) + '</span>' );
                } else {
                    showError( res.data && res.data.message ? res.data.message : medAnalit.i18n.noData );
                }
                if ( titleEl ) titleEl.textContent = 'Немає даних';
                return;
            }

            var data = res.data;

            if ( titleEl ) titleEl.textContent = 'Динаміка показника: ' + data.name;
            if ( legendName ) legendName.textContent = data.name;

            showChart();
            buildChart( data );
        } )
        .fail( function () {
            showError( medAnalit.i18n.errorNet );
        } );
    }

    /* ── Debounce для дат ── */
    function debounceLoad() {
        clearTimeout( debounceTimer );
        debounceTimer = setTimeout( loadChartData, 350 );
    }

    /* ── Ініціалізація після завантаження сторінки ── */
    window.jQuery( function () {
        // Перевіряємо що Chart.js завантажений
        if ( typeof Chart === 'undefined' ) {
            showError( 'Chart.js не завантажений. Перевірте підключення інтернету.' );
            return;
        }

        // Реєструємо annotation plugin
        if ( window.ChartAnnotation ) {
            Chart.register( window.ChartAnnotation );
        }

        selectEl.addEventListener( 'change', loadChartData );
        if ( dateFromEl ) dateFromEl.addEventListener( 'change', debounceLoad );
        if ( dateToEl )   dateToEl.addEventListener(   'change', debounceLoad );

        // Якщо показник вже вибраний (reload зі значенням)
        if ( selectEl.value ) loadChartData();
    } );

}() );
