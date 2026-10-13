<div class="row g-3 mb-4">
<?php foreach ($k as $label => $val): ?><div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="text-muted small"><?= e($label) ?></div><div class="stat-value"><?= e($val) ?></div></div></div></div><?php endforeach; ?>
</div>
