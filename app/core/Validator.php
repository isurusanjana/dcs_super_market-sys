<?php
class Validator {
    /** @param array $data input; @param array $rules field => ['label'=>..., 'rules'=>'required|max:10'] */
    static function check(array $data, array $rules, ?int $exceptId = null): array {
        $errors = [];
        foreach ($rules as $field => $def) {
            $label = $def['label'] ?? $field; $val = $data[$field] ?? null; $val = is_string($val) ? trim($val) : $val;
            $empty = ($val === null || $val === '');
            foreach (array_filter(explode('|', $def['rules'] ?? '')) as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                if ($name === 'required') { if ($empty) { $errors[$field] = "$label is required."; break; } continue; }
                if ($empty) continue;
                $msg = self::rule($name, $arg, $val, $field, $exceptId);
                if ($msg) { $errors[$field] = str_replace(':label', $label, $msg); break; }
            }
        }
        return $errors;
    }
    private static function rule(string $n, ?string $a, $v, string $field, ?int $except): ?string {
        switch ($n) {
            case 'email': return filter_var($v, FILTER_VALIDATE_EMAIL) ? null : ':label must be a valid email.';
            case 'min': return mb_strlen((string)$v) >= (int)$a ? null : ":label must be at least $a characters.";
            case 'max': return mb_strlen((string)$v) <= (int)$a ? null : ":label may not exceed $a characters.";
            case 'numeric': return is_numeric($v) ? null : ':label must be a number.';
            case 'int': return preg_match('/^-?\d+$/', (string)$v) ? null : ':label must be a whole number.';
            case 'decimal': return (is_numeric($v) && $v >= 0 && $v <= 999999999.99) ? null : ':label must be a non-negative amount.';
            case 'pos': return (is_numeric($v) && $v > 0) ? null : ':label must be greater than zero.';
            case 'minval': return (is_numeric($v) && $v >= (float)$a) ? null : ":label must be at least $a.";
            case 'maxval': return (is_numeric($v) && $v <= (float)$a) ? null : ":label may not exceed $a.";
            case 'date': $d = DateTime::createFromFormat('Y-m-d', (string)$v); return ($d && $d->format('Y-m-d') === $v) ? null : ':label must be a valid date.';
            case 'time': return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$v) ? null : ':label must be a valid time.';
            case 'phone': return preg_match('/^[0-9+\-\s()]{7,20}$/', (string)$v) ? null : ':label must be a valid phone number.';
            case 'code': return preg_match('/^[A-Za-z0-9._\-\/]+$/', (string)$v) ? null : ':label may only contain letters, digits and . _ - /';
            case 'plu': return preg_match('/^\d{1,6}$/', (string)$v) ? null : ':label must be 1 to 6 digits.';
            case 'username': return preg_match('/^[A-Za-z0-9._]{3,50}$/', (string)$v) ? null : ':label must be 3-50 chars: letters, digits, dot, underscore.';
            case 'in': return in_array((string)$v, explode(',', (string)$a), true) ? null : ':label has an invalid value.';
            case 'isbn': return self::isbn((string)$v) ? null : ':label is not a valid ISBN-10/13.';
            case 'unique': [$t, $c] = explode(',', (string)$a); $sql = "SELECT COUNT(*) FROM `$t` WHERE `$c`=?" . ($except ? ' AND id<>?' : '');
                return DB::val($sql, $except ? [$v, $except] : [$v]) ? ':label already exists.' : null;
            case 'exists': [$t, $c] = explode(',', (string)$a); return DB::val("SELECT COUNT(*) FROM `$t` WHERE `$c`=?", [$v]) ? null : ':label is invalid.';
            case 'password': return Auth::passwordError((string)$v);
        }
        return null;
    }
    static function isbn(string $s): bool {
        $s = str_replace(['-', ' '], '', strtoupper($s));
        if (preg_match('/^\d{13}$/', $s)) { $sum = 0; for ($i = 0; $i < 12; $i++) $sum += (int)$s[$i] * ($i % 2 ? 3 : 1); return ((10 - $sum % 10) % 10) === (int)$s[12]; }
        if (preg_match('/^\d{9}[\dX]$/', $s)) { $sum = 0; for ($i = 0; $i < 10; $i++) $sum += ($s[$i] === 'X' ? 10 : (int)$s[$i]) * (10 - $i); return $sum % 11 === 0; }
        return false;
    }
}
