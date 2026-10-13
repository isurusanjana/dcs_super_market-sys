<h4 class="mb-3">Product &amp; Inventory Dashboard</h4>
<?php require __DIR__ . '/_kpi.php'; ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header d-flex justify-content-between">Items needing attention <a href="<?= url('alerts/index') ?>" class="small">All alerts</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>SKU</th><th>Product</th><th class="text-end">On hand</th><th class="text-end">Reorder at</th></tr></thead><tbody>
    <?php foreach ($low as $r): ?><tr><td><?= e($r['sku']) ?></td><td><?= e($r['name']) ?></td><td class="text-end"><span class="badge text-bg-<?= $r['qty'] <= 0 ? 'danger' : 'warning' ?>"><?= fq($r['qty']) ?></span></td><td class="text-end"><?= fq($r['reorder_level']) ?></td></tr><?php endforeach; ?>
    <?php if (!$low): ?><tr><td colspan="4" class="text-muted text-center py-3">Stock levels look healthy.</td></tr><?php endif; ?></tbody></table></div></div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header">Stock units by category</div><div class="card-body"><canvas id="c3" height="200"></canvas></div></div></div>
</div>
<?php ob_start(); ?>
<script>new Chart(document.getElementById('c3'),{type:'bar',data:{labels:<?= json_encode(array_column($cats, 'n')) ?>,datasets:[{label:'Units',data:<?= json_encode(array_map('floatval', array_column($cats, 'q'))) ?>,backgroundColor:'#0d6efd'}]},options:{indexAxis:'y',plugins:{legend:{display:false}}}});</script>
<?php $scripts = ob_get_clean(); ?>
