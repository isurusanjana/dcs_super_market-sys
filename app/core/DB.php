<?php
class DB {
    private static ?PDO $pdo = null;
    static function pdo(): PDO {
        if (!self::$pdo) {
            $c = cfg('db');
            self::$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        }
        return self::$pdo;
    }
    static function q(string $sql, array $p = []): PDOStatement { $s = self::pdo()->prepare($sql); $s->execute($p); return $s; }
    static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r ?: null; }
    static function val(string $sql, array $p = []) { $r = self::q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    static function insert(string $t, array $d): int {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($d)));
        self::q("INSERT INTO `$t` ($cols) VALUES (" . implode(',', array_fill(0, count($d), '?')) . ")", array_values($d));
        return (int)self::pdo()->lastInsertId();
    }
    static function update(string $t, array $d, string $where, array $wp = []): int {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($d)));
        return self::q("UPDATE `$t` SET $set WHERE $where", array_merge(array_values($d), $wp))->rowCount();
    }
    static function tx(callable $fn) {
        $p = self::pdo();
        if ($p->inTransaction()) return $fn();
        $p->beginTransaction();
        try { $r = $fn(); $p->commit(); return $r; } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
    }
}
