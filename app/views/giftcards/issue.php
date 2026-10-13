<div class="d-flex justify-content-between mb-3"><h4>Sell a Gift Card</h4><a class="btn btn-outline-secondary" href="<?= url('giftcards/index') ?>">Back</a></div>
<?php if (!$hasSession): ?><div class="alert alert-warning">You have no open register. You can still sell a card paid by card / bank / digital, but cash needs an open register.</div><?php endif; ?>
<form method="post" class="card card-body needs-validation" novalidate><?= csrf_field() ?><div class="row g-3">
<div class="col-md-3"><label class="form-label required-star">Card value</label><input type="number" name="amount" min="1" step="0.01" class="form-control" required></div>
<div class="col-md-3"><label class="form-label required-star">Customer pays by</label><select name="method" class="form-select"><?php foreach (payment_methods() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Expiry date</label><input type="date" name="expires_on" min="<?= date('Y-m-d') ?>" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Linked customer</label><select name="customer_id" class="form-select"><option value="">-- None --</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ' ' . $c['phone']) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="160" placeholder="For example: birthday gift"></div></div>
<div class="mt-3"><button class="btn btn-primary once"><i class="bi bi-gift"></i> Issue card</button></div></form>
