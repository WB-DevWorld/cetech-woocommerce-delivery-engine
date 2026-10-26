<?php
declare(strict_types=1);

// Actual typed saved Stage/reader over the existing declared native-shaped
// objects and read-only in-memory SQLite port. This is not Woo certification.
$surface = $argv[1] ?? 'proof';
$mutation = $argv[2] ?? 'untouched';
$backend = $argv[3] ?? 'hpos';
if (!in_array($surface, ['proof', 'read'], true)
    || !in_array($mutation, ['untouched', 'deny', 'money', 'packet', 'site'], true)
    || !in_array($backend, ['hpos', 'cpt'], true)) {
    throw new InvalidArgumentException('Unknown final authority probe mode.');
}
$argv[1] = $backend;
define('R24_NATIVE_PROBE_LIBRARY', true);
require __DIR__ . '/customer-policy-native-probe.php';

use CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStage;
use CetechDeliveryEngine\Application\CustomerPolicy\PolicySavedOrderReader;

$physical = static function () use ($schemas): string {
    $rows = [];
    foreach (array_keys($schemas) as $table) {
        $rows[$table] = Probe::$db->query('SELECT * FROM owned_' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', serialize($rows));
};
$before = $physical();
$writes = Probe::$writes;
$retirements = Probe::$retirements;
$calls = 0;
$callsInSql = 0;
$late = false;
$authorize = static function () use (&$calls, &$callsInSql, &$late, $retirements, $mutation, $order, $item): bool {
    ++$calls;
    if (Probe::$owned) {
        ++$callsInSql;
        throw new LogicException('Authority callback inside owned SQL.');
    }
    // Arm only the actual final authority call, after rollback and retirement.
    // Earlier admission and physical observations therefore remain genuine.
    if (!$late && Probe::$retirements > $retirements) {
        $late = true;
        if ($mutation === 'deny') {
            return false;
        }
        if ($mutation === 'money') {
            $order->mutation();
        } elseif ($mutation === 'packet') {
            $item->replace('_cetech_de_policy_packet', 'late unsaved policy packet');
        } elseif ($mutation === 'site') {
            $GLOBALS['blog_id'] = 2;
        }
    }
    return true;
};
$returned = false;
$typed = false;
$unchanged = null;
$packets = null;
$error = null;
try {
    $reader = new PolicySavedOrderReader(new Factory());
    if ($surface === 'proof') {
        $stage = $reader->proof($order, $authorize);
        $typed = $stage instanceof PolicyNativeOrderStage;
        $unchanged = $stage->unchanged();
        $packets = count($stage->packets());
    } else {
        $packets = count($reader->read($order, $authorize));
    }
    $returned = true;
} catch (Throwable $exception) {
    $error = get_class($exception);
}
echo json_encode([
    'returned' => $returned,
    'typed_stage' => $typed,
    'unchanged' => $unchanged,
    'packets' => $packets,
    'late_authority_reached' => $late,
    'authority_calls' => $calls,
    'authority_calls_in_sql' => $callsInSql,
    'retired' => Probe::$retirements - $retirements,
    'writes' => Probe::$writes - $writes,
    'physical_unchanged' => $physical() === $before,
    'getters_in_sql' => Probe::$getters,
    'error' => $error,
    'physical_qualification' => false,
], JSON_THROW_ON_ERROR);
