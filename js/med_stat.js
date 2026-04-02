/* global medStat, jQuery */
/**
 * med_stat.js — Medical Statistics frontend
 *
 * Changes vs original:
 *  - loadOrders() sends user_id only when medStat.isAdmin === true, sourcing the
 *    value from the #ms-user-filter <select> that the server renders for admins.
 *  - deleteOrder() treats a 403 / "Доступ заборонено" response as a permission
 *    error and shows the server message rather than a generic failure toast.
 *  - userFilter element reference added to the el map.
 *  - Admin-only user-filter change listener registered (no-op for regular users
 *    because the element simply does not exist in the DOM for them).
 */
(function () {
    'use strict';
    var jq = window.jQuery;
    if (!jq) { console.error('[MedStat] jQuery відсутній'); return; }

    var State = { currentPage: 1, totalPages: 1, activeOrderId: null, filterTimer: null };

    var el = {
        search          : document.getElementById('ms-search'),
        dateFrom        : document.getElementById('ms-date-from'),
        dateTo          : document.getElementById('ms-date-to'),
        // User filter — only present in the DOM when medStat.isAdmin === true.
        userFilter      : document.getElementById('ms-user-filter'),
        orderList       : document.getElementById('ms-order-list'),
        pagination      : document.getElementById('ms-pagination'),
        prevPage        : document.getElementById('ms-prev-page'),
        nextPage        : document.getElementById('ms-next-page'),
        pageCur         : document.getElementById('ms-page-current'),
        pageTotal       : document.getElementById('ms-page-total'),
        orderMeta       : document.getElementById('ms-order-meta'),
        tableWrap       : document.getElementById('ms-table-wrap'),
        importBtn       : document.getElementById('ms-import-btn'),
        pdfInput        : document.getElementById('ms-pdf-input'),
        addBtn          : document.getElementById('ms-add-btn'),
        mainPanel       : document.getElementById('ms-main'),
        rowTpl          : document.getElementById('ms-row-tpl'),
        // Edit modal
        modal           : document.getElementById('ms-modal'),
        modalOverlay    : document.getElementById('ms-modal-overlay'),
        modalClose      : document.getElementById('ms-modal-close'),
        modalCancel     : document.getElementById('ms-modal-cancel'),
        modalSave       : document.getElementById('ms-modal-save'),
        // Add modal
        addModal        : document.getElementById('ms-add-modal'),
        addModalOverlay : document.getElementById('ms-add-modal-overlay'),
        addModalClose   : document.getElementById('ms-add-modal-close'),
        addModalCancel  : document.getElementById('ms-add-modal-cancel'),
        addModalSave    : document.getElementById('ms-add-modal-save'),
        addRowBtn       : document.getElementById('ms-add-row-btn'),
        measRows        : document.getElementById('ms-meas-rows'),
        measRowTpl      : document.getElementById('ms-meas-row-tpl'),
    };

    /* ── Toast ─────────────────────────────────────────────────────────────── */
    var toastEl = null, toastTimer = null;
    function initToast() {
        if (toastEl) return;
        toastEl = document.getElementById('ms-toast');
    }
    function showToast(msg, type) {
        initToast(); if (!toastEl) return;
        toastEl.className = 'ms-toast ms-toast--' + (type || 'info');
        toastEl.textContent = msg;
        toastEl.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.style.display = 'none'; }, type === 'warning' ? 6000 : 4000);
    }

    /* ── Formatting helpers ─────────────────────────────────────────────────── */
    function fmtDatetime(dt) {
        if (!dt) return '—';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var p = function (n) { return String(n).padStart(2, '0'); };
        return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ', ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function fmtDate(dt) {
        if (!dt) return '—';
        var d = new Date((dt + ' ').replace(' ', 'T').replace(/T$/, ''));
        if (isNaN(d.getTime())) {
            var p = dt.split(/[\s\-\/]/);
            if (p.length >= 3) return p[2] + '.' + p[1] + '.' + p[0];
            return dt;
        }
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear();
    }
    function cleanNum(n) {
        if (n === null || n === undefined) return '—';
        var f = parseFloat(n);
        return isNaN(f) ? String(n) : f.toString();
    }
    function esc(s) {
        var d = document.createElement('div'); d.textContent = String(s || ''); return d.innerHTML;
    }

    /* ── AJAX: orders list ──────────────────────────────────────────────────── */
    function loadOrders(page) {
        page = page || 1;
        State.currentPage = page;

        var params = {
            action   : 'med_stat_get_orders',
            nonce    : medStat.nonce,
            page     : page,
            date_from: el.dateFrom ? el.dateFrom.value : '',
            date_to  : el.dateTo   ? el.dateTo.value   : '',
            search   : el.search   ? el.search.value   : '',
        };

        /*
         * User filter — only sent when:
         *   1. The server flagged this session as an admin (medStat.isAdmin), AND
         *   2. The #ms-user-filter element exists (it is never rendered for
         *      non-admins so this is a belt-and-suspenders guard), AND
         *   3. A specific user has been selected (value > 0).
         *
         * The server ignores user_id if the caller is not an admin, providing an
         * additional authoritative check on the PHP side.
         */
        if (medStat.isAdmin && el.userFilter && parseInt(el.userFilter.value, 10) > 0) {
            params.user_id = el.userFilter.value;
        }

        jq.post(medStat.ajaxUrl, params)
            .done(function (res) {
                if (!res.success) {
                    showToast(res.data && res.data.message || medStat.i18n.noResults, 'error');
                    renderOrderList([]);
                    return;
                }
                renderOrderList(res.data.orders || []);
                updatePagination(res.data.current_page, res.data.pages);
            })
            .fail(function () { showToast(medStat.i18n.importFail, 'error'); });
    }

    function renderOrderList(orders) {
        if (!el.orderList) return;
        el.orderList.innerHTML = '';
        if (!orders.length) {
            var p = document.createElement('p');
            p.className = 'ms-empty';
            p.textContent = medStat.i18n.noResults;
            el.orderList.appendChild(p);
            return;
        }
        orders.forEach(function (o, idx) {
            var isActive = (o.id === State.activeOrderId) || (State.activeOrderId === null && idx === 0);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ms-order-item' + (isActive ? ' ms-order-item--active' : '');
            btn.dataset.orderId = o.id;
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            var num  = document.createElement('span'); num.className = 'ms-order-item__num';  num.textContent = '№ ' + (o.order_number || '—');
            var date = document.createElement('span'); date.className = 'ms-order-item__date'; date.textContent = fmtDate(o.collection_date);
            btn.appendChild(num); btn.appendChild(date);
            el.orderList.appendChild(btn);
        });
    }

    /* ── AJAX: order detail ─────────────────────────────────────────────────── */
    function loadOrder(orderId) {
        if (!orderId) return;
        State.activeOrderId = orderId;
        highlightActive(orderId);
        jq.post(medStat.ajaxUrl, { action: 'med_stat_get_order', nonce: medStat.nonce, order_id: orderId })
            .done(function (res) {
                if (!res.success) {
                    showToast(res.data && res.data.message || medStat.i18n.importFail, 'error');
                    return;
                }
                renderHeader(res.data.order);
                renderMeasurements(res.data.measurements || []);
            })
            .fail(function () { showToast(medStat.i18n.importFail, 'error'); });
    }

    function highlightActive(id) {
        if (!el.orderList) return;
        el.orderList.querySelectorAll('.ms-order-item').forEach(function (b) {
            var is = parseInt(b.dataset.orderId, 10) === id;
            b.classList.toggle('ms-order-item--active', is);
            b.setAttribute('aria-pressed', is ? 'true' : 'false');
        });
    }

    function renderHeader(order) {
        if (!el.orderMeta) return;
        var num = order.order_number || '—';
        var dt  = fmtDatetime(order.collection_date);
        var dob = order.patient_dob ? order.patient_dob.replace(/(\d{4})-(\d{2})-(\d{2})/, '$3.$2.$1') : '';

        el.orderMeta.innerHTML =
            '<div class="ms-order-meta__top">' +
              '<h2 class="ms-order-meta__title">' + medStat.i18n.orderLabel + ' ' + esc(num) + '</h2>' +
              '<span class="ms-order-meta__date">' + esc(dt) + '</span>' +
            '</div>' +
            '<div class="ms-order-meta__details">' +
              '<span>Пацієнт: ' + esc(order.patient_name || '') + '</span>' +
              (dob ? '<span class="ms-dot" aria-hidden="true">·</span><span>Дата народження: ' + esc(dob) + '</span>' : '') +
            '</div>' +
            '<div class="ms-order-meta__details">' +
              (order.doctor_name ? '<span>Лікар: ' + esc(order.doctor_name) + '</span>' : '') +
              (order.branch_info ? '<span class="ms-dot" aria-hidden="true">·</span><span>' + esc(order.branch_info) + '</span>' : '') +
            '</div>';

        // Render/update the delete button.
        var deleteBtn = document.getElementById('ms-delete-btn');
        if (!deleteBtn) {
            deleteBtn = document.createElement('button');
            deleteBtn.type = 'button'; deleteBtn.id = 'ms-delete-btn';
            deleteBtn.className = 'ms-btn ms-btn--danger';
            deleteBtn.title = 'Видалити замовлення';
            deleteBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            var actions = document.querySelector('.ms-main__actions');
            if (actions) actions.insertBefore(deleteBtn, actions.firstChild);
        }
        deleteBtn.dataset.orderId = order.id;
    }

    /* ── Measurement table ──────────────────────────────────────────────────── */
    function renderMeasurements(measurements) {
        if (!el.tableWrap) return;
        el.tableWrap.innerHTML = '';

        if (!measurements.length) {
            var p = document.createElement('p'); p.className = 'ms-empty ms-empty--main'; p.textContent = medStat.i18n.noResults;
            el.tableWrap.appendChild(p); return;
        }

        var head = document.createElement('div'); head.className = 'ms-table-head'; head.setAttribute('role', 'row');
        ['Показник', 'Результат', 'Реф. знач.', ''].forEach(function (label, i) {
            var col = document.createElement('div');
            col.className = 'ms-col ' + ['ms-col--indicator', 'ms-col--result', 'ms-col--ref', 'ms-col--action'][i];
            col.setAttribute('role', 'columnheader');
            col.textContent = label; head.appendChild(col);
        });
        el.tableWrap.appendChild(head);

        measurements.forEach(function (m) {
            if (!el.rowTpl) return;
            var clone = el.rowTpl.content.cloneNode(true);
            var row   = clone.querySelector('.ms-row');
            if (!row) return;
            row.dataset.measId = m.meas_id;
            row.dataset.indId  = m.ind_id;
            if (!m.is_normal) row.classList.add('ms-row--abnormal');

            clone.querySelector('.ms-indicator__name').textContent = m.name || '';

            var catEl = clone.querySelector('.ms-indicator__category');
            if (m.category) catEl.textContent = m.category; else catEl.remove();

            var hintEl = clone.querySelector('.ms-indicator__hint');
            if (m.interpretation_hint) hintEl.textContent = m.interpretation_hint; else hintEl.remove();

            var valEl = clone.querySelector('.ms-value');
            valEl.textContent = cleanNum(m.result_value);
            if (!m.is_normal) valEl.classList.add('ms-value--abnormal');

            clone.querySelectorAll('.ms-unit').forEach(function (u) {
                if (m.measure) u.textContent = m.measure; else u.remove();
            });

            var refEl = clone.querySelector('.ms-ref');
            if (m.min !== null && m.max !== null) {
                refEl.textContent = cleanNum(m.min) + ' – ' + cleanNum(m.max);
            } else {
                refEl.textContent = '—';
                var ru = clone.querySelector('.ms-col--ref .ms-unit');
                if (ru) ru.remove();
            }

            el.tableWrap.appendChild(clone);
        });
    }

    /* ── Pagination ─────────────────────────────────────────────────────────── */
    function updatePagination(cur, total) {
        State.currentPage = cur; State.totalPages = total;
        if (el.pageCur)   el.pageCur.textContent   = cur;
        if (el.pageTotal) el.pageTotal.textContent = total;
        if (el.prevPage)  el.prevPage.disabled = (cur <= 1);
        if (el.nextPage)  el.nextPage.disabled = (cur >= total);
        if (el.pagination) el.pagination.classList.toggle('ms-pagination--hidden', total <= 1);
    }

    /* ── Import PDF ─────────────────────────────────────────────────────────── */
    function importPdf(file) {
        if (!file) return;
        showToast(medStat.i18n.loading, 'info');
        var fd = new FormData();
        fd.append('action',   'med_stat_import_pdf');
        fd.append('nonce',    medStat.nonce);
        fd.append('pdf_file', file);
        jq.ajax({ url: medStat.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false })
            .done(function (res) {
                if (!res.success) {
                    var msg = res.data && res.data.message || medStat.i18n.importFail;
                    if (res.data && res.data.duplicate && res.data.order_id) {
                        showToast(msg, 'warning');
                        loadOrders(1); loadOrder(res.data.order_id);
                    } else { showToast(msg, 'error'); }
                    return;
                }
                showToast(medStat.i18n.importOk, 'success');
                State.activeOrderId = res.data.order_id;
                var today = new Date();
                var pad = function (n) { return String(n).padStart(2, '0'); };
                var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
                if (el.dateTo && el.dateTo.value < todayStr) el.dateTo.value = todayStr;
                loadOrders(1); loadOrder(res.data.order_id);
            })
            .fail(function () { showToast(medStat.i18n.importFail, 'error'); })
            .always(function () { if (el.pdfInput) el.pdfInput.value = ''; });
    }

    /* ── Delete order ───────────────────────────────────────────────────────── */
    function deleteOrder(orderId) {
        if (!confirm(medStat.i18n.deleteConfirm)) return;
        jq.post(medStat.ajaxUrl, { action: 'med_stat_delete_order', nonce: medStat.nonce, order_id: orderId })
            .done(function (res) {
                if (!res.success) {
                    /*
                     * Surface the server's message verbatim — this covers both the
                     * generic error case and the 403 "Доступ заборонено" path so
                     * users understand why the deletion was denied.
                     */
                    showToast(res.data && res.data.message || 'Помилка видалення', 'error');
                    return;
                }
                showToast(medStat.i18n.deleteOk, 'success');
                State.activeOrderId = null;
                if (el.orderMeta) el.orderMeta.innerHTML = '<h2 class="ms-order-meta__title">Медична статистика</h2><p class="ms-text-muted">Оберіть замовлення</p>';
                if (el.tableWrap) el.tableWrap.innerHTML = '<div class="ms-welcome"><p>Оберіть замовлення або імпортуйте PDF.</p></div>';
                var db = document.getElementById('ms-delete-btn'); if (db) db.remove();
                loadOrders(State.currentPage);
            })
            .fail(function () { showToast('Помилка видалення', 'error'); });
    }

    /* ── Edit modal ─────────────────────────────────────────────────────────── */
    function openModal(row) {
        if (!el.modal) return;
        var measId = row.dataset.measId;
        var indId  = row.dataset.indId;
        document.getElementById('ms-edit-meas-id').value  = measId;
        document.getElementById('ms-edit-ind-id').value   = indId;
        document.getElementById('ms-edit-name').value     = (row.querySelector('.ms-indicator__name')     || {textContent:''}).textContent.trim();
        document.getElementById('ms-edit-measure').value  = (row.querySelector('.ms-unit')                || {textContent:''}).textContent.trim();
        document.getElementById('ms-edit-category').value = (row.querySelector('.ms-indicator__category') || {textContent:''}).textContent.trim();
        document.getElementById('ms-edit-result').value   = (row.querySelector('.ms-value')               || {textContent:''}).textContent.trim();
        var refText = (row.querySelector('.ms-ref') || {textContent:''}).textContent;
        var refM    = refText.match(/([\d.]+)\s*[–\-]\s*([\d.]+)/);
        document.getElementById('ms-edit-min').value = refM ? refM[1] : '';
        document.getElementById('ms-edit-max').value = refM ? refM[2] : '';
        el.modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        if (el.modal) el.modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    function saveModal() {
        var measId = document.getElementById('ms-edit-meas-id').value;
        var indId  = document.getElementById('ms-edit-ind-id').value;
        jq.post(medStat.ajaxUrl, {
            action       : 'med_stat_edit_row',
            nonce        : medStat.nonce,
            meas_id      : measId,
            ind_id       : indId,
            name         : document.getElementById('ms-edit-name').value,
            measure      : document.getElementById('ms-edit-measure').value,
            category     : document.getElementById('ms-edit-category').value,
            min          : document.getElementById('ms-edit-min').value,
            max          : document.getElementById('ms-edit-max').value,
            result_value : document.getElementById('ms-edit-result').value,
        })
            .done(function (res) {
                closeModal();
                showToast(res.success ? medStat.i18n.saveOk : (res.data && res.data.message || 'Помилка'), res.success ? 'success' : 'error');
                if (res.success && State.activeOrderId) loadOrder(State.activeOrderId);
            })
            .fail(function () { showToast('Помилка збереження', 'error'); });
    }

    /* ── Add-order modal ────────────────────────────────────────────────────── */
    function openAddModal() {
        if (!el.addModal) return;
        if (el.measRows) el.measRows.innerHTML = '';
        addMeasRow();
        el.addModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeAddModal() {
        if (el.addModal) el.addModal.style.display = 'none';
        document.body.style.overflow = '';
    }

    function addMeasRow() {
        if (!el.measRowTpl || !el.measRows) return;
        var clone = el.measRowTpl.content.cloneNode(true);
        var row   = clone.querySelector('.ms-meas-row');

        var searchInput  = row.querySelector('.ms-ind-search');
        var dropdown     = row.querySelector('.ms-ind-dropdown');
        var indIdInput   = row.querySelector('.ms-ind-id');
        var minInput     = row.querySelector('.ms-ind-min');
        var maxInput     = row.querySelector('.ms-ind-max');
        var measureInput = row.querySelector('.ms-ind-measure');

        var searchTimer = null;

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = this.value.trim();
            if (q.length < 1) { dropdown.style.display = 'none'; dropdown.innerHTML = ''; return; }
            searchTimer = setTimeout(function () {
                jq.post(medStat.ajaxUrl, { action: 'med_stat_search_indicators', nonce: medStat.nonce, q: q })
                    .done(function (res) {
                        dropdown.innerHTML = '';
                        if (!res.success || !res.data.items.length) { dropdown.style.display = 'none'; return; }
                        res.data.items.forEach(function (item) {
                            var opt = document.createElement('div');
                            opt.className = 'ms-ind-option';
                            opt.textContent = item.name;
                            opt.dataset.id  = item.id;
                            opt.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                searchInput.value  = item.name;
                                indIdInput.value   = item.id;
                                minInput.value     = item.min  !== null ? item.min  : '';
                                maxInput.value     = item.max  !== null ? item.max  : '';
                                measureInput.value = item.measure || '';
                                dropdown.style.display = 'none';
                            });
                            dropdown.appendChild(opt);
                        });
                        dropdown.style.display = 'block';
                    });
            }, 300);
        });
        searchInput.addEventListener('blur', function () {
            setTimeout(function () { dropdown.style.display = 'none'; }, 200);
        });

        row.querySelector('.ms-row-del-btn').addEventListener('click', function () { row.remove(); });

        el.measRows.appendChild(clone);
    }

    function saveAddOrder() {
        var orderNum   = (document.getElementById('ms-add-order-num')   || {value:''}).value.trim();
        var collection = (document.getElementById('ms-add-collection')   || {value:''}).value.trim();
        var doctor     = (document.getElementById('ms-add-doctor')       || {value:''}).value.trim();
        var branch     = (document.getElementById('ms-add-branch')       || {value:''}).value.trim();
        var patient    = (document.getElementById('ms-add-patient')      || {value:''}).value.trim();
        var dob        = (document.getElementById('ms-add-dob')          || {value:''}).value.trim();

        var rows = [];
        if (el.measRows) {
            el.measRows.querySelectorAll('.ms-meas-row').forEach(function (r) {
                var name  = (r.querySelector('.ms-ind-search')   || {value:''}).value.trim();
                var indId = (r.querySelector('.ms-ind-id')       || {value:''}).value.trim();
                var rv    = (r.querySelector('.ms-meas-value')   || {value:''}).value.trim();
                var exec  = (r.querySelector('.ms-meas-date')    || {value:''}).value.trim();
                var min   = (r.querySelector('.ms-ind-min')      || {value:''}).value.trim();
                var max   = (r.querySelector('.ms-ind-max')      || {value:''}).value.trim();
                var meas  = (r.querySelector('.ms-ind-measure')  || {value:''}).value.trim();
                if (!name || rv === '') return;
                rows.push({ name: name, ind_id: indId, result_value: rv, execution_date: exec, min: min, max: max, measure: meas });
            });
        }
        if (!rows.length) { showToast('Додайте хоча б один показник з результатом.', 'error'); return; }

        jq.post(medStat.ajaxUrl, {
            action           : 'med_stat_add_order',
            nonce            : medStat.nonce,
            order_number     : orderNum,
            collection_date  : collection,
            doctor_name      : doctor,
            branch_info      : branch,
            patient_name     : patient,
            patient_dob      : dob,
            rows             : rows,
        })
            .done(function (res) {
                if (!res.success) {
                    var msg = res.data && res.data.message || 'Помилка збереження';
                    if (res.data && res.data.duplicate && res.data.order_id) {
                        showToast(msg, 'warning'); closeAddModal();
                        loadOrders(1); loadOrder(res.data.order_id);
                    } else { showToast(msg, 'error'); }
                    return;
                }
                showToast(res.data.message || medStat.i18n.saveOk, 'success');
                closeAddModal();
                State.activeOrderId = res.data.order_id;
                var today = new Date(); var pad = function (n) { return String(n).padStart(2, '0'); };
                var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
                if (el.dateTo && el.dateTo.value < todayStr) el.dateTo.value = todayStr;
                loadOrders(1); loadOrder(res.data.order_id);
            })
            .fail(function () { showToast('Помилка збереження', 'error'); });
    }

    /* ── Initialise ─────────────────────────────────────────────────────────── */
    jq(function () {
        var booting = true;
        setTimeout(function () { booting = false; }, 200);

        loadOrders(1);

        if (el.search) el.search.addEventListener('input', function () {
            clearTimeout(State.filterTimer);
            State.filterTimer = setTimeout(function () { loadOrders(1); }, 400);
        });
        if (el.dateFrom) el.dateFrom.addEventListener('change', function () {
            if (el.dateTo) el.dateTo.min = el.dateFrom.value;
            if (!booting) loadOrders(1);
        });
        if (el.dateTo) el.dateTo.addEventListener('change', function () {
            if (el.dateFrom) el.dateFrom.max = el.dateTo.value;
            if (!booting) loadOrders(1);
        });

        /*
         * Admin user filter — el.userFilter is null for non-admins so this
         * addEventListener call is simply skipped for them.
         */
        if (el.userFilter) el.userFilter.addEventListener('change', function () { loadOrders(1); });

        if (el.prevPage) el.prevPage.addEventListener('click', function () { if (State.currentPage > 1) loadOrders(State.currentPage - 1); });
        if (el.nextPage) el.nextPage.addEventListener('click', function () { if (State.currentPage < State.totalPages) loadOrders(State.currentPage + 1); });

        if (el.orderList) el.orderList.addEventListener('click', function (e) {
            var btn = e.target.closest('.ms-order-item');
            if (btn) loadOrder(parseInt(btn.dataset.orderId, 10));
        });

        if (el.importBtn && el.pdfInput) el.importBtn.addEventListener('click', function () { el.pdfInput.click(); });
        if (el.pdfInput) el.pdfInput.addEventListener('change', function () { if (this.files && this.files[0]) importPdf(this.files[0]); });

        if (el.addBtn) el.addBtn.addEventListener('click', openAddModal);

        // Drag & drop PDF onto the main panel.
        if (el.mainPanel) {
            el.mainPanel.addEventListener('dragover',  function (e) { e.preventDefault(); el.mainPanel.classList.add('ms-drag-over'); });
            el.mainPanel.addEventListener('dragleave', function ()  { el.mainPanel.classList.remove('ms-drag-over'); });
            el.mainPanel.addEventListener('drop', function (e) {
                e.preventDefault(); el.mainPanel.classList.remove('ms-drag-over');
                var dt = e.dataTransfer; if (dt && dt.files && dt.files[0]) importPdf(dt.files[0]);
            });
        }

        // Delete — delegated to document so it works after renderHeader() recreates the button.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('#ms-delete-btn');
            if (btn) deleteOrder(parseInt(btn.dataset.orderId, 10));
        });

        // Edit — delegated to tableWrap.
        if (el.tableWrap) el.tableWrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.ms-edit-btn');
            if (btn) { var row = btn.closest('.ms-row'); if (row) openModal(row); }
        });

        // Edit modal.
        if (el.modalClose)   el.modalClose.addEventListener('click',   closeModal);
        if (el.modalCancel)  el.modalCancel.addEventListener('click',  closeModal);
        if (el.modalOverlay) el.modalOverlay.addEventListener('click', closeModal);
        if (el.modalSave)    el.modalSave.addEventListener('click',    saveModal);

        // Add modal.
        if (el.addModalClose)   el.addModalClose.addEventListener('click',   closeAddModal);
        if (el.addModalCancel)  el.addModalCancel.addEventListener('click',  closeAddModal);
        if (el.addModalOverlay) el.addModalOverlay.addEventListener('click', closeAddModal);
        if (el.addModalSave)    el.addModalSave.addEventListener('click',    saveAddOrder);
        if (el.addRowBtn)       el.addRowBtn.addEventListener('click',       addMeasRow);

        // ESC closes any open modal.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeModal(); closeAddModal(); }
        });

        if (el.pagination) {
            var saved = parseInt(el.pagination.dataset.total || '1', 10);
            State.totalPages = saved; updatePagination(1, saved);
        }
    });
}());
