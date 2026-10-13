<?php /** vars: $products, $lines (existing: product_id, qty, price), $withPrice (bool) */
$withPrice = $withPrice ?? true; $lines = $lines ?: [['product_id' => '', 'qty' => 1, 'price' => '']]; ?>
<div class="table-responsive"><table class="table table-sm align-middle" id="lines"><thead class="table-light"><tr><th>Product</th><th style="width:110px">Qty</th><?php if ($withPrice): ?><th style="width:140px">Unit price</th><th style="width:120px" class="text-end">Total</th><?php endif; ?><th style="width:40px"></th></tr></thead><tbody>
<?php foreach ($lines as $l): ?><tr><td><select name="product_id[]" class="form-select form-select-sm pid"><option value="">-- Select product --</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" data-cost="<?= e($p['cost_price']) ?>" <?= (string)$l['product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['sku'] . ' - ' . $p['name']) ?></option><?php endforeach; ?></select></td>
<td><input type="number" name="qty[]" min="0.001" step="any" value="<?= e($l['qty']) ?>" class="form-control form-control-sm qty"></td>
<?php if ($withPrice): ?><td><input type="number" name="price[]" min="0" step="0.01" value="<?= e($l['price']) ?>" class="form-control form-control-sm price"></td><td class="text-end lt">0.00</td><?php endif; ?>
<td><button type="button" class="btn btn-sm btn-outline-danger rm"><i class="bi bi-x"></i></button></td></tr><?php endforeach; ?></tbody>
<?php if ($withPrice): ?><tfoot><tr><td colspan="3" class="text-end fw-bold">Grand total</td><td class="text-end fw-bold" id="gt">0.00</td><td></td></tr></tfoot><?php endif; ?></table></div>
<button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addLine"><i class="bi bi-plus"></i> Add line</button>
<?php ob_start(); ?><script>
(function(){var tb=document.querySelector('#lines tbody');
function calc(){var g=0;tb.querySelectorAll('tr').forEach(function(r){var q=+r.querySelector('.qty').value||0,p=r.querySelector('.price');var t=p?q*(+p.value||0):0;var c=r.querySelector('.lt');if(c)c.textContent=t.toFixed(2);g+=t;});var gt=document.getElementById('gt');if(gt)gt.textContent=g.toFixed(2);}
tb.addEventListener('input',calc);
tb.addEventListener('change',function(e){if(e.target.classList.contains('pid')){var o=e.target.selectedOptions[0],p=e.target.closest('tr').querySelector('.price');if(p&&o&&o.dataset.cost&&!+p.value){p.value=o.dataset.cost;}calc();}});
tb.addEventListener('click',function(e){var b=e.target.closest('.rm');if(b&&tb.rows.length>1){b.closest('tr').remove();calc();}});
document.getElementById('addLine').addEventListener('click',function(){var r=tb.rows[0].cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value=i.classList.contains('qty')?1:'';});r.querySelector('select').selectedIndex=0;tb.appendChild(r);});
calc();})();
</script><?php $scripts = ob_get_clean(); ?>
