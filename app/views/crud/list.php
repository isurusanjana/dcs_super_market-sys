<?php $slug = $e['slug']; $canCreate = Auth::can($e['perm'] . '.create'); $canEdit = Auth::can($e['perm'] . '.edit'); $canDel = Auth::can($e['perm'] . '.delete'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="mb-0"><?= e($e['title']) ?> <span class="badge text-bg-light"><?= (int)$total ?></span></h4>
  <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= url('crud/form', ['e' => $slug]) ?>"><i class="bi bi-plus-lg"></i> New</a><?php endif; ?>
</div>
<form class="row g-2 mb-3" method="get"><input type="hidden" name="r" value="crud/list"><input type="hidden" name="e" value="<?= e($slug) ?>">
  <?php if (!empty($e['search'])): ?><div class="col-md-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search..."></div><?php endif; ?>
  <?php foreach ($filters as $f): ?><div class="col-md-3"><select class="form-select" name="f_<?= e($f['name']) ?>" data-autosubmit><option value="">All <?= e($f['label']) ?></option>
    <?php foreach ($f['options'] as $k => $l): ?><option value="<?= e($k) ?>" <?= (string)$f['value'] === (string)$k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endforeach; ?>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if ($q !== '' || array_filter(array_column($filters, 'value'))): ?><a class="btn btn-link" href="<?= url('crud/list', ['e' => $slug]) ?>">Clear</a><?php endif; ?></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
<thead class="table-light"><tr><?php foreach ($e['list'] as $k => $def): $lab = is_array($def) ? $def[0] : $def; ?><th><?= e($lab) ?></th><?php endforeach; ?><th class="text-end no-print">Actions</th></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td colspan="<?= count($e['list']) + 1 ?>" class="text-center text-muted py-4">No records found.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $locked = $ctl->lockedRow($r); ?>
<tr><?php foreach ($e['list'] as $k => $def): $fmt = is_array($def) ? $def[1] : 'text'; $v = $r[$k] ?? null; ?><td>
  <?php switch ($fmt) {
    case 'money': echo e(money($v)); break;
    case 'date': echo e(fmt_date($v)); break;
    case 'badge': echo badge((string)$v); break;
    case 'bool': echo $v ? '<i class="bi bi-check-circle-fill text-success"></i>' : ''; break;
    case 'active': echo badge($v ? 'active' : 'inactive'); break;
    case 'image': echo $v ? '<img src="' . e(base_url() . '/uploads/products/' . $v) . '" width="36" height="36" class="rounded object-fit-cover" alt="">' : '<span class="text-muted"><i class="bi bi-image"></i></span>'; break;
    default: echo isset($e['links'][$k]) && $v !== null && $v !== '' ? ref_link($e['links'][$k][0], $r[$e['links'][$k][1]] ?? 0, (string)$v) : e($v);
  } ?></td><?php endforeach; ?>
  <td class="text-end text-nowrap no-print">
    <?php foreach ($e['row_actions'] ?? [] as $ra): [$lab, $route, $perm, $icon] = $ra; $cond = $ra[4] ?? null; $post = $ra[5] ?? false;
      if (!Auth::can($perm)) continue; if ($cond) { foreach ($cond as $c => $val) if (($r[$c] ?? null) != $val) continue 2; }
      $href = base_url() . '/index.php?r=' . str_replace('{id}', (string)$r['id'], $route);
      if ($post): ?><form method="post" action="<?= e($href) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" title="<?= e($lab) ?>"><i class="bi <?= $icon ?>"></i></button></form>
      <?php else: ?><a class="btn btn-sm btn-outline-secondary" title="<?= e($lab) ?>" href="<?= e($href) ?>"><i class="bi <?= $icon ?>"></i></a><?php endif; endforeach; ?>
    <?php if (!$locked): ?>
      <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" title="Edit" href="<?= url('crud/form', ['e' => $slug, 'id' => $r['id']]) ?>"><i class="bi bi-pencil"></i></a>
        <?php if (isset($e['soft']) || array_key_exists('is_active', $r)): ?><form method="post" action="<?= url('crud/toggle', ['e' => $slug]) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-warning" title="Activate / deactivate"><i class="bi bi-toggle-on"></i></button></form><?php endif; ?><?php endif; ?>
      <?php if ($canDel): ?><form method="post" action="<?= url('crud/delete', ['e' => $slug]) ?>" class="d-inline" data-confirm="<?= !empty($e['archive']) ? 'Archive this record?' : 'Delete this record permanently?' ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="<?= !empty($e['archive']) ? 'Archive' : 'Delete' ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?>
    <?php else: ?><span class="text-muted small"><i class="bi bi-lock"></i></span><?php endif; ?>
  </td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?><nav class="mt-3 no-print"><ul class="pagination pagination-sm">
<?php for ($i = 1; $i <= $pages; $i++): if ($i !== 1 && $i !== $pages && abs($i - $page) > 3) { if (abs($i - $page) === 4) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; continue; } ?>
  <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= url('crud/list', ['e' => $slug, 'q' => $q, 'page' => $i] + array_filter(array_combine(array_map(fn($f) => 'f_' . $f['name'], $filters), array_column($filters, 'value')) ?: [])) ?>"><?= $i ?></a></li>
<?php endfor; ?></ul></nav><?php endif; ?>
