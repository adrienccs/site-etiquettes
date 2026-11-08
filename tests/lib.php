<?php
declare(strict_types=1);

// Mini-outil de test, sans dépendance.
$GLOBALS['__t'] = ['ok' => 0, 'ko' => 0];

function t(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['ok']++;
        echo "  ✔ $name\n";
    } catch (\Throwable $e) {
        $GLOBALS['__t']['ko']++;
        echo "  ✘ $name\n      " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}

function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($msg ? "$msg — " : '') . 'attendu ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', obtenu ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}

function ok($cond, string $msg = 'condition fausse'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function throws(callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }
    throw new \RuntimeException('exception attendue');
}

function done(): void
{
    $r = $GLOBALS['__t'];
    echo "\n{$r['ok']} réussi(s), {$r['ko']} échec(s)\n";
    exit($r['ko'] > 0 ? 1 : 0);
}
