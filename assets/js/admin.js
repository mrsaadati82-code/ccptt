jQuery(function ($) {
  const $rows = $('#cptt-steps-rows');
  const stepTpl = $('#cptt-step-template').html() || '';
  const checkItemTpl = $('#cptt-checkitem-template').html() || '';

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'cptt_' + Math.random().toString(16).slice(2) + '_' + Date.now();
  }

  function reindexAll() {
    $rows.find('.cptt-step-row').each(function (i) {
      // reindex step fields
      $(this).find('input, select, textarea').each(function () {
        const $el = $(this);
        const name = $el.attr('name');
        if (!name) return;
        $el.attr('name', name.replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']'));
      });

      // update checklist container attribute
      $(this).find('.cptt-checkitems').attr('data-step-index', i);

      // reindex checklist items
      $(this).find('.cptt-checkitem-row').each(function (j) {
        $(this).find('input').each(function () {
          const $el = $(this);
          const name = $el.attr('name');
          if (!name) return;
          // cptt_steps[i][checklist][j]
          $el.attr('name', name
            .replace(/cptt_steps\[\d+\]/, 'cptt_steps[' + i + ']')
            .replace(/\[checklist\]\[\d+\]/, '[checklist][' + j + ']')
          );
        });
      });
    });
  }

  // add step
  $('#cptt-add-step').on('click', function () {
    const i = $rows.find('.cptt-step-row').length;
    const id = uuid();
    const html = stepTpl.replaceAll('{{i}}', i).replaceAll('{{uuid}}', id);
    $rows.append(html);
    reindexAll();
  });

  // remove step
  $rows.on('click', '.cptt-remove-step', function () {
    $(this).closest('.cptt-step-row').remove();
    reindexAll();
  });

  // sortable steps
  $rows.sortable({
    items: '.cptt-step-row',
    handle: '.cptt-drag-handle',
    axis: 'y',
    tolerance: 'pointer',
    update: function () { reindexAll(); }
  });

  // toggle checklist
  $rows.on('click', '.cptt-toggle-checklist', function () {
    const $wrap = $(this).closest('.cptt-step-row').find('.cptt-checklist-wrap').first();
    $wrap.toggle();
  });

  // add checklist item
  $rows.on('click', '.cptt-add-checkitem', function () {
    const $step = $(this).closest('.cptt-step-row');
    const i = $step.index(); // temporary; will reindex after append
    const $list = $step.find('.cptt-checkitems').first();
    const j = $list.find('.cptt-checkitem-row').length;
    const cid = uuid();

    const html = checkItemTpl
      .replaceAll('{{i}}', i)
      .replaceAll('{{j}}', j)
      .replaceAll('{{cid}}', cid);

    $list.append(html);

    // make checklist sortable inside this step
    if (!$list.data('sortable')) {
      $list.sortable({
        items: '.cptt-checkitem-row',
        handle: '.cptt-checkitem-handle',
        axis: 'y',
        update: function () { reindexAll(); }
      });
      $list.data('sortable', true);
    }

    reindexAll();
  });

  // remove checklist item
  $rows.on('click', '.cptt-remove-checkitem', function () {
    $(this).closest('.cptt-checkitem-row').remove();
    reindexAll();
  });

  // init sortable for existing checklists
  $rows.find('.cptt-checkitems').each(function () {
    const $list = $(this);
    $list.sortable({
      items: '.cptt-checkitem-row',
      handle: '.cptt-checkitem-handle',
      axis: 'y',
      update: function () { reindexAll(); }
    });
    $list.data('sortable', true);
  });

  // Apply Steps Template (project only)
  $('#cptt_apply_template_btn').on('click', function () {
    const tplId = $('#cptt_template_select').val();
    if (!tplId) return;

    if (!confirm('اعمال تمپلیت، مراحل فعلی را جایگزین می‌کند. ادامه می‌دهید؟')) return;

    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_template_steps',
      nonce: CPTT_ADMIN.nonce,
      template_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت');

      const steps = res.data || [];
      $rows.empty();

      steps.forEach(function (s, idx) {
        const id = s.id || uuid();
        const html = stepTpl.replaceAll('{{i}}', idx).replaceAll('{{uuid}}', id);
        const $row = $(html);

        $row.find('input[name^="cptt_steps[' + idx + '][title]"]').val(s.title || '');
        $row.find('select[name^="cptt_steps[' + idx + '][status]"]').val(s.status || 'todo');
        $row.find('textarea[name^="cptt_steps[' + idx + '][desc]"]').val(s.desc || '');

        // checklist items
        const cl = Array.isArray(s.checklist) ? s.checklist : [];
        const $list = $row.find('.cptt-checkitems').first();
        cl.forEach(function (it, j) {
          const cid = it.id || uuid();
          const itemHtml = checkItemTpl
            .replaceAll('{{i}}', idx)
            .replaceAll('{{j}}', j)
            .replaceAll('{{cid}}', cid);
          const $itRow = $(itemHtml);
          $itRow.find('input[type="text"]').val(it.text || '');
          $itRow.find('input[type="checkbox"]').prop('checked', !!it.done);
          $list.append($itRow);
        });

        $rows.append($row);
      });

      // re-init sortables
      reindexAll();
      $rows.sortable('refresh');
      $rows.find('.cptt-checkitems').each(function () {
        const $list = $(this);
        if ($list.data('sortable')) return;
        $list.sortable({
          items: '.cptt-checkitem-row',
          handle: '.cptt-checkitem-handle',
          axis: 'y',
          update: function () { reindexAll(); }
        });
        $list.data('sortable', true);
      });
    });
  });

  // Apply Checklist Template inside a step
  $rows.on('click', '.cptt-apply-checktpl', function () {
    const $step = $(this).closest('.cptt-step-row');
    const tplId = $step.find('.cptt-checktpl-select').val();
    if (!tplId) return;

    $.get(CPTT_ADMIN.ajax, {
      action: 'cptt_get_checklist_tpl',
      nonce: CPTT_ADMIN.nonce,
      checktpl_id: tplId
    }).done(function (res) {
      if (!res || !res.success) return alert('خطا در دریافت تمپلیت چک‌لیست');

      const items = res.data || [];
      const $list = $step.find('.cptt-checkitems').first();
      $list.empty();

      // add rows
      items.forEach(function (text, j) {
        const idx = $step.index();
        const cid = uuid();
        const html = checkItemTpl
          .replaceAll('{{i}}', idx)
          .replaceAll('{{j}}', j)
          .replaceAll('{{cid}}', cid);
        const $itRow = $(html);
        $itRow.find('input[type="text"]').val(text || '');
        $itRow.find('input[type="checkbox"]').prop('checked', false);
        $list.append($itRow);
      });

      reindexAll();
    });
  });

  // Checklist template editor page
  $('#cptt-add-checktpl-row').on('click', function () {
    const $c = $('#cptt-checktpl-rows');
    const tpl = $('#cptt-checktpl-row-template').html() || '';
    const i = $c.find('.cptt-checktpl-row').length;
    $c.append(tpl.replaceAll('{{i}}', i));
  });

  $(document).on('click', '.cptt-remove-checktpl-row', function () {
    $(this).closest('.cptt-checktpl-row').remove();
  });
});