<?php
class SettingsController extends Controller {
    protected ?string $module = 'admin';
    private function defs(): array {
        return [
            'business_name' => ['Business name', 'required|max:120'], 'business_address' => ['Address', 'max:255'], 'business_phone' => ['Phone', 'phone'],
            'currency' => ['Currency symbol', 'required|max:8'], 'tax_rate' => ['Default tax rate %', 'required|decimal|maxval:100'],
            'return_days' => ['Return window (days)', 'required|int|minval:0|maxval:3650'], 'max_cashier_discount' => ['Max cashier discount % without manager approval', 'required|decimal|maxval:100'],
            'loyalty_per_amount' => ['Loyalty: 1 point per this amount spent (0 = disabled)', 'required|decimal'], 'allow_negative_stock' => ['Allow selling below zero stock (1 = yes, 0 = no)', 'required|in:0,1'],
            'prices_include_tax' => ['Selling prices include tax (1 = yes, 0 = tax is added at the till)', 'required|in:0,1'], 'expiry_alert_days' => ['Warn about expiry this many days ahead', 'required|int|minval:1|maxval:365'],
            'loyalty_redeem_value' => ['Loyalty: value of 1 point when redeemed at the POS', 'required|decimal'], 'scale_label_prefix' => ['Scale label barcode prefix digit (weighing scale labels start with this)', 'required|in:2'],
            'receipt_language' => ['Receipt / invoice language (si = Sinhala, en = English, both = Sinhala / English)', 'required|in:si,en,both'],
            'receipt_footer' => ['Receipt footer message (leave as is for the standard thank-you in the chosen language)', 'max:255'],
        ];
    }
    function action_index(): void {
        $this->need('admin.settings.view'); $defs = $this->defs(); $errors = [];
        if (is_post()) {
            $this->need('admin.settings.edit'); $in = []; foreach ($defs as $k => $_) $in[$k] = trim((string)($_POST[$k] ?? ''));
            $rules = []; foreach ($defs as $k => [$l, $r]) $rules[$k] = ['label' => $l, 'rules' => $r];
            $errors = Validator::check($in, $rules);
            if (!$errors) { $diff = []; foreach ($in as $k => $v) { if ((string)setting($k, '') !== $v) $diff[$k] = [setting($k, ''), $v]; set_setting($k, $v); } audit('settings_change', 'settings', null, $diff); flash('success', 'Settings saved.'); redirect('settings/index'); }
        }
        $vals = []; foreach ($defs as $k => $_) $vals[$k] = is_post() ? ($_POST[$k] ?? '') : setting($k, '');
        $this->render('settings/index', ['title' => 'Settings', 'defs' => $defs, 'vals' => $vals, 'errors' => $errors]);
    }
}
