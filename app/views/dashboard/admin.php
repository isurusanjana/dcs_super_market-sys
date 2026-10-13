<h4 class="mb-3">Administration Dashboard</h4>
<?php require __DIR__ . '/_kpi.php'; ?>
<div class="row g-3">
  <div class="col-lg-8"><div class="card"><div class="card-header">Sales - last 14 days</div><div class="card-body"><canvas id="c1" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card mb-3"><div class="card-header">Payment methods (this month)</div><div class="card-body"><canvas id="c2" height="160"></canvas></div></div></div>
  <div class="col-12"><div class="card"><div class="card-header">Top selling products (30 days)</div><ul class="list-group list-group-flush">
    <?php foreach ($top as $t): ?><li class="list-group-item d-flex justify-content-between"><?= e($t['name']) ?><span class="badge text-bg-primary"><?= fq($t['q']) ?></span></li><?php endforeach; ?>
    <?php if (!$top): ?><li class="list-group-item text-muted">No sales yet.</li><?php endif; ?></ul></div></div>
</div>
<?php ob_start(); ?>
<script>
new Chart(document.getElementById('c1'),{type:'line',data:{labels:<?= json_encode($labels) ?>,datasets:[{label:'Sales',data:<?= json_encode($vals) ?>,borderColor:'#0d6efd',backgroundColor:'rgba(13,110,253,.1)',fill:true,tension:.3}]},options:{plugins:{legend:{display:false}}}});
new Chart(document.getElementById('c2'),{type:'doughnut',data:{labels:<?= json_encode(array_map(fn($p) => ucwords(str_replace('_', ' ', $p['method'])), $pay)) ?>,datasets:[{data:<?= json_encode(array_map(fn($p) => (float)$p['a'], $pay)) ?>}]}});
</script>
<?php $scripts = ob_get_clean(); ?>
