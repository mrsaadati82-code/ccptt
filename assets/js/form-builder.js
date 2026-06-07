/* HAM Bale Form Builder v6.0.0 — Conditional Logic + Deep Link + Panel Buttons + Import/Export + AutoFill */
(function ($) {
    'use strict';
    if (typeof CPTT_FB === 'undefined') return;
    var FB = CPTT_FB;

    /* ─────────────────────────────────────────────
       UTILITIES
    ───────────────────────────────────────────── */
    function uid() { return 'f_' + Math.random().toString(36).slice(2, 9); }
    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function def(type) { return (FB.types && FB.types[type]) || FB.types.text || {}; }

    /* ─────────────────────────────────────────────
       BUILD FIELD ROW HTML
    ───────────────────────────────────────────── */
    function row(f) {
        f = f || {};
        var type      = f.type || 'text';
        var d         = def(type);
        var id        = f.id || uid();
        var canBranch = d.can_branch ? '1' : '0';
        var hasBranch = Array.isArray(f.branches) && f.branches.length > 0;
        var autoFillTypes = FB.autofill_types || [];

        var autoFillNote = (autoFillTypes.indexOf(type) !== -1)
            ? '<span class="cptt-fb-autofill-badge" title="اگر autofill فعال باشد و اطلاعات کاربر موجود باشد این مرحله رد می‌شود">⚡ autofill</span>'
            : '';

        /* --- Field Body --- */
        var body = '<input type="hidden" class="cptt-fb-field-section-label" value="' + esc(f.section_label || '') + '">';
        if (f.section_label) body += '<div class="cptt-fb-section-hint">بخش: <b>' + esc(f.section_label) + '</b></div>';
        body += '<label><span>پیام راهنما در بله</span><input class="cptt-fb-field-help" value="' + esc(f.help || '') + '"></label>';

        if (['text','textarea','number','phone','email','date','address'].indexOf(type) !== -1) {
            body += '<label><span>نمونه/Placeholder</span><input class="cptt-fb-field-placeholder" value="' + esc(f.placeholder || '') + '"></label>';
        }
        if (['buttons','multi'].indexOf(type) !== -1) {
            body += '<label class="full"><span>گزینه‌ها — هر گزینه در یک خط</span><textarea class="cptt-fb-field-options" rows="4">' + esc(f.options || '') + '</textarea></label>';
            body += '<label><span>چیدمان</span><select class="cptt-fb-field-layout"><option value="grid">شبکه‌ای</option><option value="list">لیستی</option></select></label>';
        }
        if (type === 'file') {
            body += '<label><input type="checkbox" class="cptt-fb-field-multiple" ' + (f.multiple ? 'checked' : '') + '> چند فایل مجاز</label>';
            body += '<label><span>حداکثر فایل</span><input type="number" class="cptt-fb-field-max-files" value="' + esc(f.max_files || 5) + '"></label>';
        }
        if (type === 'payment') {
            body += '<label><span>مبلغ (اختیاری)</span><input class="cptt-fb-field-amount" value="' + esc(f.amount || '') + '"></label>';
            body += '<label><input type="checkbox" class="cptt-fb-field-allow-later" ' + (f.allow_later ? 'checked' : '') + '> امکان پرداخت بعداً</label>';
        }
        if (type === 'intro' || type === 'confirm') {
            body += '<label class="full"><span>متن پیام</span><textarea class="cptt-fb-field-message" rows="3">' + esc(f.message || '') + '</textarea></label>';
        }

        /* --- Branch Editor --- */
        var branchHtml = '';
        if (canBranch === '1') {
            var branchRows = '';
            if (Array.isArray(f.branches)) {
                f.branches.forEach(function (br) {
                    branchRows += buildBranchRow(br);
                });
            }
            branchHtml = '<div class="cptt-fb-branch-editor ' + (hasBranch ? 'is-open' : '') + '">' +
                '<div class="cptt-fb-branch-title">🔀 منطق شرطی <span class="cptt-fb-branch-info">برای هر گزینه مسیر و پیام نهایی تعیین کنید</span></div>' +
                '<div class="cptt-fb-branches">' + branchRows + '</div>' +
                '<button class="cptt-fb-branch-add" type="button">+ افزودن شرط</button>' +
                '<p class="cptt-fb-branch-hint">💡 <b>section_label</b>: در فیلدهایی که فقط در یک مسیر نمایش می‌خواهید همین برچسب را وارد کنید.</p>' +
                '</div>';
        }

        /* --- Final HTML --- */
        return '<li class="cptt-fb-field ' + (hasBranch ? 'has-branch' : '') + '" ' +
            'data-id="' + esc(id) + '" data-type="' + esc(type) + '" data-can-branch="' + canBranch + '">' +
            '<div class="cptt-fb-fieldHead">' +
            '<span class="cptt-fb-drag" title="بکشید">⠿</span>' +
            '<span class="cptt-fb-type-icon">' + esc(d.icon || '') + '</span>' +
            '<b class="cptt-fb-type-label">' + esc(d.label || type) + '</b>' + autoFillNote +
            '<input class="cptt-fb-field-label" value="' + esc(f.label || '') + '" placeholder="عنوان مرحله">' +
            '<label class="cptt-fb-check-label"><input type="checkbox" class="cptt-fb-field-required" ' + (f.required ? 'checked' : '') + '> اجباری</label>' +
            (canBranch === '1' ? '<button class="cptt-fb-branch-toggle" type="button" title="منطق شرطی">🔀 شرط</button>' : '') +
            '<button class="cptt-fb-field-remove" type="button" title="حذف">×</button>' +
            '</div>' +
            '<div class="cptt-fb-fieldBody">' + body + '</div>' +
            branchHtml +
            '</li>';
    }

    function buildBranchRow(br) {
        br = br || {};
        return '<div class="cptt-fb-branch-row">' +
            '<div class="cptt-fb-branch-left">' +
            '<label><span>اگر انتخاب کرد:</span><input class="cptt-fb-branch-option" value="' + esc(br.option || '') + '" placeholder="نام گزینه"></label>' +
            '</div>' +
            '<div class="cptt-fb-branch-arrow">→</div>' +
            '<div class="cptt-fb-branch-right">' +
            '<label><span>برو به بخش (section_label):</span><input class="cptt-fb-branch-goto" value="' + esc(br.goto_label || '') + '" placeholder="مثلاً: بهبود_سایت"></label>' +
            '<label class="full"><span>پیام نهایی این مسیر:</span><textarea class="cptt-fb-branch-final" rows="2" placeholder="پیشنهاد/پیام پایانی...">' + esc(br.final_message || '') + '</textarea></label>' +
            '</div>' +
            '<button class="cptt-fb-branch-remove" type="button" title="حذف">×</button>' +
            '</div>';
    }

    /* ─────────────────────────────────────────────
       SORTABLE
    ───────────────────────────────────────────── */
    function sortable() {
        var $u = $('#cptt-fb-fields');
        if ($u.length && $.fn.sortable) {
            if ($u.data('ui-sortable')) $u.sortable('destroy');
            $u.sortable({
                handle: '.cptt-fb-drag',
                placeholder: 'cptt-fb-placeholder',
                forcePlaceholderSize: true,
                tolerance: 'pointer'
            });
        }
    }

    /* ─────────────────────────────────────────────
       COLLECT DATA
    ───────────────────────────────────────────── */
    function collect() {
        var arr = [];
        $('#cptt-fb-fields .cptt-fb-field').each(function () {
            var $f = $(this);
            var branches = [];
            $f.find('.cptt-fb-branch-row').each(function () {
                var $br = $(this);
                branches.push({
                    option:        $br.find('.cptt-fb-branch-option').val() || '',
                    goto_label:    $br.find('.cptt-fb-branch-goto').val() || '',
                    final_message: $br.find('.cptt-fb-branch-final').val() || '',
                });
            });
            arr.push({
                id:           $f.data('id'),
                type:         $f.data('type'),
                label:        $f.find('.cptt-fb-field-label').val() || '',
                required:     $f.find('.cptt-fb-field-required').is(':checked') ? 1 : 0,
                placeholder:  $f.find('.cptt-fb-field-placeholder').val() || '',
                help:         $f.find('.cptt-fb-field-help').val() || '',
                options:      $f.find('.cptt-fb-field-options').val() || '',
                layout:       $f.find('.cptt-fb-field-layout').val() || 'grid',
                multiple:     $f.find('.cptt-fb-field-multiple').is(':checked') ? 1 : 0,
                max_files:    $f.find('.cptt-fb-field-max-files').val() || 5,
                amount:       $f.find('.cptt-fb-field-amount').val() || '',
                allow_later:  $f.find('.cptt-fb-field-allow-later').is(':checked') ? 1 : 0,
                message:      $f.find('.cptt-fb-field-message').val() || '',
                section_label:$f.find('.cptt-fb-field-section-label').val() || '',
                branches:     branches,
            });
        });
        return arr;
    }

    /* ─────────────────────────────────────────────
       INIT
    ───────────────────────────────────────────── */
    sortable();
    // Set layout selects to correct value on load
    $('#cptt-fb-fields .cptt-fb-field').each(function () {
        var $f = $(this);
    });

    /* ─────────────────────────────────────────────
       EVENTS: PALETTE
    ───────────────────────────────────────────── */
    $(document).on('click', '.cptt-fb-add-field', function (e) {
        e.preventDefault();
        $('#cptt-fb-fields').append(row({ type: $(this).data('type') }));
        sortable();
    });

    $(document).on('click', '.cptt-fb-field-remove', function (e) {
        e.preventDefault();
        if (confirm('این مرحله حذف شود؟')) $(this).closest('.cptt-fb-field').remove();
    });

    /* ─────────────────────────────────────────────
       EVENTS: TEMPLATES
    ───────────────────────────────────────────── */
    $(document).on('click', '.cptt-fb-templates button', function (e) {
        e.preventDefault();
        var t = $(this).data('template');
        if (!confirm('مراحل فعلی با تمپلیت جایگزین شود؟')) return;
        var tplFields = (FB.templates && FB.templates[t]) || [];
        $('#cptt-fb-fields').html(tplFields.map(row).join(''));
        sortable();
    });

    /* ─────────────────────────────────────────────
       EVENTS: BRANCH EDITOR
    ───────────────────────────────────────────── */
    // Toggle branch editor
    $(document).on('click', '.cptt-fb-branch-toggle', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.cptt-fb-field').find('.cptt-fb-branch-editor');
        $editor.toggleClass('is-open');
        $(this).closest('.cptt-fb-field').toggleClass('has-branch', $editor.hasClass('is-open') && $editor.find('.cptt-fb-branch-row').length > 0);
    });

    // Add branch row
    $(document).on('click', '.cptt-fb-branch-add', function (e) {
        e.preventDefault();
        $(this).closest('.cptt-fb-branch-editor').find('.cptt-fb-branches').append(buildBranchRow({}));
        $(this).closest('.cptt-fb-field').addClass('has-branch');
    });

    // Remove branch row
    $(document).on('click', '.cptt-fb-branch-remove', function (e) {
        e.preventDefault();
        var $editor = $(this).closest('.cptt-fb-branch-editor');
        $(this).closest('.cptt-fb-branch-row').remove();
        if ($editor.find('.cptt-fb-branch-row').length === 0) {
            $(this).closest('.cptt-fb-field').removeClass('has-branch');
        }
    });

    /* ─────────────────────────────────────────────
       EVENTS: SECTION LABEL (از branch گرفته می‌شود)
    ───────────────────────────────────────────── */
    // وقتی مدیر روی یک مرحله section_label می‌نویسد، نشان داده شود
    $(document).on('change', '.cptt-fb-field-section-label', function () {
        var val = $(this).val();
        var $hint = $(this).closest('.cptt-fb-fieldBody').find('.cptt-fb-section-hint');
        if (val) {
            if ($hint.length) $hint.html('بخش: <b>' + esc(val) + '</b>');
            else $(this).closest('.cptt-fb-fieldBody').prepend('<div class="cptt-fb-section-hint">بخش: <b>' + esc(val) + '</b></div>');
        } else {
            $hint.remove();
        }
    });

    /* ─────────────────────────────────────────────
       EVENTS: DEEP LINK & FORM KEY
    ───────────────────────────────────────────── */


    // Copy button
    $(document).on('click', '.cptt-fb-copy-btn', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        var text = $('#' + target).text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta);
            ta.select(); document.execCommand('copy');
            document.body.removeChild(ta);
        }
        var $btn = $(this);
        $btn.text('✅ کپی شد!');
        setTimeout(function () { $btn.text('📋 کپی'); }, 1500);
    });

    /* ─────────────────────────────────────────────
       EVENTS: NEW FORM
    ───────────────────────────────────────────── */
    $(document).on('click', '#cptt-fb-new-form', function (e) {
        e.preventDefault();
        var title = prompt('نام فرم جدید', 'فرم سفارش بله');
        if (!title) return;
        $.post(FB.ajax, { action: 'cptt_form_save', nonce: FB.nonce, id: 0, title: title, fields: [] }, function (r) {
            if (r && r.success) location.href = location.pathname + '?post_type=cptt_project&page=cptt-form-builder&form_id=' + r.data.id + '&tab=builder';
        });
    });

    /* ─────────────────────────────────────────────
       EVENTS: SAVE FORM
    ───────────────────────────────────────────── */
    $(document).on('click', '#cptt-fb-save', function (e) {
        e.preventDefault();
        var $b = $(this).prop('disabled', true).text('در حال ذخیره...');
        $.post(FB.ajax, {
            action:             'cptt_form_save',
            nonce:              FB.nonce,
            id:                 $('#cptt-fb-form-id').val(),
            title:              $('#cptt-fb-form-title').val(),
            form_key:           $('#cptt-fb-form-key').val() || '',
            target_product_id:  $('#cptt-fb-target-product').val() || 0,
            target_cat_id:      $('#cptt-fb-target-cat').val() || 0,
            final_message:      $('#cptt-fb-final-message').val() || '',
            submit_message:     $('#cptt-fb-submit-message').val() || '',
            autofill:           $('#cptt-fb-autofill').is(':checked') ? 1 : 0,
            bale_btn_enabled:   $('#cptt-fb-btn-enabled').is(':checked') ? 1 : 0,
            bale_btn_label:     $('#cptt-fb-btn-label').val() || '',
            bale_btn_position:  $('#cptt-fb-btn-position').val() || 0,
            bale_btn_row:       $('#cptt-fb-btn-row').val() || 0,
            fields:             collect(),
        }, function (r) {
            $b.prop('disabled', false);
            if (r && r.success) {
                $b.text('✅ ذخیره شد');
                if (r.data && r.data.deep_link) {
                    $('#cptt-fb-deeplink').text(r.data.deep_link);
                }
            } else {
                $b.text('❌ خطا');
            }
            setTimeout(function () { $b.text('💾 ذخیره فرم'); }, 2000);
        });
    });

    /* ─────────────────────────────────────────────
       EVENTS: ACTIVATE / DEACTIVATE / DELETE
    ───────────────────────────────────────────── */
    $(document).on('click', '#cptt-fb-set-active', function (e) {
        e.preventDefault();
        $.post(FB.ajax, { action: 'cptt_form_activate', nonce: FB.nonce, id: $('#cptt-fb-form-id').val() }, function (r) {
            if (r && r.success) location.reload();
        });
    });
    $(document).on('click', '#cptt-fb-deactivate', function (e) {
        e.preventDefault();
        $.post(FB.ajax, { action: 'cptt_form_deactivate', nonce: FB.nonce }, function (r) {
            if (r && r.success) location.reload();
        });
    });
    $(document).on('click', '#cptt-fb-delete', function (e) {
        e.preventDefault();
        if (!confirm('این فرم حذف شود؟')) return;
        $.post(FB.ajax, { action: 'cptt_form_delete', nonce: FB.nonce, id: $('#cptt-fb-form-id').val() }, function () {
            location.href = location.pathname + '?post_type=cptt_project&page=cptt-form-builder';
        });
    });

    /* ─────────────────────────────────────────────
       EVENTS: EXPORT
    ───────────────────────────────────────────── */
    $(document).on('click', '#cptt-fb-export', function (e) {
        e.preventDefault();
        var id = $('#cptt-fb-form-id').val();
        if (!id) { alert('ابتدا یک فرم انتخاب کنید.'); return; }
        $.post(FB.ajax, { action: 'cptt_form_export', nonce: FB.nonce, id: id }, function (r) {
            if (!r || !r.success || !r.data || !r.data.json) { alert('خطا در اکسپورت.'); return; }
            var blob = new Blob([r.data.json], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'cptt-form-' + id + '.json';
            a.click();
        });
    });

    /* ─────────────────────────────────────────────
       EVENTS: IMPORT
    ───────────────────────────────────────────── */
    $(document).on('change', '#cptt-fb-import-file', function () {
        var file = this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var json = e.target.result;
            try { JSON.parse(json); } catch (err) { alert('فایل JSON نامعتبر است.'); return; }
            if (!confirm('فرم از فایل وارد شود؟')) return;
            $.post(FB.ajax, { action: 'cptt_form_import', nonce: FB.nonce, json: json }, function (r) {
                if (r && r.success && r.data && r.data.id) {
                    location.href = location.pathname + '?post_type=cptt_project&page=cptt-form-builder&form_id=' + r.data.id + '&tab=builder';
                } else {
                    alert('خطا در ایمپورت: ' + (r && r.data ? r.data : 'نامشخص'));
                }
            });
        };
        reader.readAsText(file);
        this.value = '';
    });

    /* ─────────────────────────────────────────────
       TARGET PRODUCT FILTER
    ───────────────────────────────────────────── */
    function filterTargetProducts() {
        var cat = String($('#cptt-fb-target-cat').val() || '0');
        var $prod = $('#cptt-fb-target-product');
        if (!$prod.length) return;
        var current = $prod.val();
        var currentVisible = true;
        $prod.find('option').each(function () {
            if (!this.value || this.value === '0') { this.hidden = false; return; }
            var cats = String($(this).data('cats') || '').split(',');
            var show = (cat === '0' || cats.indexOf(cat) !== -1);
            this.hidden = !show;
            if (this.value === current && !show) currentVisible = false;
        });
        if (!currentVisible) $prod.val('0');
    }
    $(document).on('change', '#cptt-fb-target-cat', filterTargetProducts);
    $(filterTargetProducts);

    /* ─────────────────────────────────────────────
       PANEL BUTTONS SORTABLE
    ───────────────────────────────────────────── */
    /* ─────────────────────────────────────────────
       PANEL BUTTONS - sortable + row/col editor
    ───────────────────────────────────────────── */
    function initPanelSortable() {
        var $grid = $('#cptt-panel-grid');
        if (!$grid.length || !$.fn.sortable) return;
        if ($grid.data('ui-sortable')) $grid.sortable('destroy');
        $grid.sortable({
            handle: '.cptt-fb-drag',
            tolerance: 'pointer',
            placeholder: 'cptt-panel-placeholder',
            forcePlaceholderSize: true,
            update: function () { renderPanelGrid(); }
        });
    }

    function renderPanelGrid() {
        /* گروه‌بندی بصری بر اساس row_idx */
        var $grid = $('#cptt-panel-grid');
        var items = [];
        $grid.find('.cptt-panel-item').each(function () {
            items.push({
                el: this,
                row: parseInt($(this).find('.cptt-panel-row').val()) || 0,
                col: parseInt($(this).find('.cptt-panel-col').val()) || 0,
            });
        });
        items.sort(function(a,b){ return a.row===b.row ? a.col-b.col : a.row-b.row; });
        items.forEach(function(it){ $grid.append(it.el); });
    }

    function collectPanelButtons() {
        var buttons = [];
        $('#cptt-panel-grid .cptt-panel-item').each(function () {
            var $item = $(this);
            buttons.push({
                id:      $item.data('id') || '',
                label:   $item.find('.cptt-panel-label').val() || '',
                enabled: $item.find('.cptt-panel-enabled').is(':checked') ? 1 : 0,
                row_idx: parseInt($item.find('.cptt-panel-row').val()) || 0,
                col_idx: parseInt($item.find('.cptt-panel-col').val()) || 0,
                solo:    $item.find('.cptt-panel-solo').is(':checked') ? 1 : 0,
            });
        });
        return buttons;
    }

    initPanelSortable();

    // ─── بروزرسانی نمای grid وقتی row تغییر کرد
    $(document).on('change input', '.cptt-panel-row, .cptt-panel-col', function () {
        renderPanelGrid();
    });

    // ─── دکمه بالا/پایین برای ردیف
    $(document).on('click', '.cptt-panel-row-up', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.cptt-panel-item');
        var $inp  = $item.find('.cptt-panel-row');
        $inp.val(Math.max(0, parseInt($inp.val())-1));
        renderPanelGrid();
    });
    $(document).on('click', '.cptt-panel-row-dn', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.cptt-panel-item');
        var $inp  = $item.find('.cptt-panel-row');
        $inp.val(parseInt($inp.val())+1);
        renderPanelGrid();
    });
    $(document).on('click', '.cptt-panel-col-prev', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.cptt-panel-item');
        var $inp  = $item.find('.cptt-panel-col');
        $inp.val(Math.max(0, parseInt($inp.val())-1));
        renderPanelGrid();
    });
    $(document).on('click', '.cptt-panel-col-next', function (e) {
        e.preventDefault();
        var $item = $(this).closest('.cptt-panel-item');
        var $inp  = $item.find('.cptt-panel-col');
        $inp.val(parseInt($inp.val())+1);
        renderPanelGrid();
    });

    // ─── ذخیره پنل
    $(document).on('click', '#cptt-panel-save', function (e) {
        e.preventDefault();
        var $b = $(this).prop('disabled', true).text('در حال ذخیره...');
        $.post(FB.ajax, {
            action: 'cptt_panel_buttons_save',
            nonce:  FB.nonce,
            buttons: collectPanelButtons()
        }, function (r) {
            $b.prop('disabled', false);
            $b.text(r && r.success ? '✅ ذخیره شد' : '❌ خطا');
            setTimeout(function () { $b.text('💾 ذخیره پنل'); }, 2000);
        });
    });

    /* ─────────────────────────────────────────────
       DEEP LINK با bot_username
    ───────────────────────────────────────────── */
    function buildDeepLink(formKey) {
        var username = (FB.bot_username || '').replace(/^@/, '');
        if (username) {
            return 'https://ble.ir/' + username + '?start=form_' + formKey;
        }
        return 'https://ble.ir/BOT_USERNAME?start=form_' + formKey;
    }

    // بروزرسانی deep link
    $('#cptt-fb-deeplink').text(buildDeepLink($('#cptt-fb-form-key').val() || $('#cptt-fb-form-id').val() || ''));
    $(document).on('input', '#cptt-fb-form-key', function () {
        var key = $(this).val() || $('#cptt-fb-form-id').val() || '';
        $('#cptt-fb-deeplink').text(buildDeepLink(key));
    });

})(jQuery);
