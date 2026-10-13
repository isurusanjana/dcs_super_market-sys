<h4 class="mb-3">POS Dashboard</h4>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">Register</div>
    <?php if ($sess): ?><div class="stat-value"><?= badge($sess['status']) ?></div><div class="small text-muted mt-1"><?= e($sess['session_no']) ?> &middot; opened <?= e(fmt_date($sess['opened_at'], 'H:i')) ?></div>
      <a class="btn btn-success btn-sm mt-2" href="<?= url('pos/terminal') ?>">Open terminal</a>
    <?php else: ?><div class="stat-value text-muted">Closed</div><?php if (Auth::can('pos.session.open')): ?><a class="btn btn-primary btn-sm mt-2" href="<?= url('pos/session') ?>">Open register</a><?php endif; endif; ?></div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">My sales today</div><div class="stat-value"><?= e(money($mine['t'])) ?></div></div></div></div>
  <div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted small">My transactions today</div><div class="stat-value"><?= (int)$mine['n'] ?></div></div></div></div>
</div>
<div class="card"><div class="card-header">My recent transactions</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><a href="<?= url('pos/sale', ['id' => $r['id']]) ?>"><?= e($r['invoice_no']) ?></a></td><td><?= e(fmt_date($r['sale_date'], 'd M H:i')) ?></td><td class="text-end"><?= e(money($r['total'])) ?></td><td><?= badge($r['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="4" class="text-muted text-center py-3">No transactions yet.</td></tr><?php endif; ?></tbody></table></div></div>
