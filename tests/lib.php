<?php
/** BarberPay — mini-outil de test (aucune dépendance). */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$GLOBALS['bp_tests'] = ['ok' => 0, 'echecs' => 0];

function section(string $titre): void
{
    echo $titre . "\n";
}

function ok(bool $condition, string $nom, mixed $obtenu = null): void
{
    if ($condition) {
        $GLOBALS['bp_tests']['ok']++;
        echo "  OK    $nom\n";
    } else {
        $GLOBALS['bp_tests']['echecs']++;
        echo "  ÉCHEC $nom" . ($obtenu !== null ? ' → ' . (is_scalar($obtenu) ? (string) $obtenu : json_encode($obtenu, JSON_UNESCAPED_UNICODE)) : '') . "\n";
    }
}

/** Vérifie qu'un appel lève une exception dont le message contient $extrait. */
function refuse(callable $appel, string $extrait, string $nom): void
{
    try {
        $appel();
        ok(false, $nom, 'accepté');
    } catch (Throwable $e) {
        ok(str_contains($e->getMessage(), $extrait), $nom, $e->getMessage());
    }
}

function fin(): never
{
    ['ok' => $ok, 'echecs' => $echecs] = $GLOBALS['bp_tests'];
    echo "\n" . ($ok + $echecs) . " tests, $ok réussis" . ($echecs ? ", $echecs ÉCHEC(S)" : '') . "\n";
    exit($echecs ? 1 : 0);
}
