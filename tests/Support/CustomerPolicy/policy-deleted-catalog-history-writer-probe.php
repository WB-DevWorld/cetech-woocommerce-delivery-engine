<?php
declare(strict_types=1);

// Genuine receipt/first-writer/immutable packet production over the existing
// declared native CRUD and SQLite ports. No WordPress or catalog is booted.
$backend = $argv[1] ?? 'hpos';
$mode = $argv[2] ?? 'both_deleted';
if (!in_array($backend, ['hpos', 'cpt'], true)) {
    throw new InvalidArgumentException('Unknown owned history backend.');
}
$argv[1] = $backend;
define('R24_WRITER_PROBE_LIBRARY', true);
require __DIR__ . '/customer-policy-writer-probe.php';

use CetechDeliveryEngine\Application\CustomerPolicy\{CartPolicyReceiptService, CustomerPolicyCurrentObservation, CustomerPolicyReadGrant, CustomerPolicySessionOwner, PolicyNativeOrderFacts, PolicyNativeOrderStager, PolicySavedOrderReader};
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\CustomerPolicyDomainFixture;

$allowed = ['untouched', 'simple_deleted', 'product_deleted', 'variation_deleted', 'both_deleted', 'wrong_positive', 'dirty_zero', 'dirty_equal_expected', 'filtered_zero', 'raw_original_string', 'physical_product_duplicate', 'physical_variation_duplicate', 'physical_wrong_product', 'physical_wrong_variation', 'physical_noncanonical', 'physical_malformed', 'physical_null', 'physical_legacy_changed', 'physical_legacy_malformed', 'physical_packet_changed', 'late_money', 'late_packet', 'late_coordinate', 'late_site', 'late_deny'];
if (!in_array($mode, $allowed, true)) {
    throw new InvalidArgumentException('Unknown owned history mode.');
}
$variation = $mode !== 'simple_deleted';
$rawProperty = static function (object $object, string $name): mixed {
    $property = new ReflectionProperty(WC_Data::class, $name);
    return method_exists($property, 'getRawValue') ? $property->getRawValue($object) : $property->getValue($object);
};
$setProperty = static function (object $object, string $name, mixed $value): void {
    (new ReflectionProperty(WC_Data::class, $name))->setValue($object, $value);
};
$setCoordinate = static function (WC_Order_Item_Product $item, string $field, mixed $value) use ($rawProperty, $setProperty): void {
    $data = $rawProperty($item, 'data');
    $data[$field] = $value;
    $setProperty($item, 'data', $data);
};

if ($variation) {
    // A new genuine receipt owner avoids reusing the simple fixture's frozen original.
    $subject = str_repeat('b', 64);
    $owner = CustomerPolicySessionOwner::captured(1, 7, $subject, ['site_id' => 1, 'prefix' => 'owned_'], static function (): bool {
        Probe::getter();
        return true;
    });
    $frame['session_subject'] = $subject;
    $frame['variation_id'] = 44;
    $frame['parent_id'] = 33;
    $grant = CustomerPolicyReadGrant::captured($frame, ['site_id' => 1, 'prefix' => 'owned_'], static function (): bool {
        Probe::getter();
        return true;
    });
    $observation = CustomerPolicyCurrentObservation::captured($grant, CustomerPolicyDomainFixture::families(true), RuleTime::parse(CustomerPolicyDomainFixture::NOW));
    $service = new CartPolicyReceiptService($store, static fn () => str_repeat('owned-secret', 5), static fn () => $owner, static function (array $frames) use (&$source_reads, $observation): array {
        ++$source_reads;
        return ['native-key' => $observation];
    }, static function () use (&$uuid): string {
        return CustomerPolicyDomainFixture::uuid(++$uuid);
    });
    $view = $service->present_line($grant, $observation);
    if (!$service->accept_line($view['ticket'], $grant, $observation)) {
        throw new RuntimeException('Owned variation review did not complete.');
    }
    $p = $service->freeze_original('classic', 1, ['native-key' => $grant], ['native-key' => $observation]);
    $lineData = json_decode($rawline, true, 16, JSON_THROW_ON_ERROR);
    $lineData['variation_id'] = 44;
    $rawline = json_encode($lineData, JSON_THROW_ON_ERROR);
}

$stager = new PolicyNativeOrderStager(new Factory());
[$order, $item] = writer_initial_attempt($p, $stager, 123, 456, true, static function (WC_Order $order, WC_Order_Item_Product $item) use ($variation, $setCoordinate, $stager, $p): void {
    if ($variation) {
        $setCoordinate($item, 'variation_id', 44);
    }
    $stager->begin_attempt($order, $p);
});
// The shared declared CRUD port seeds a simple line. Complete its variation
// coordinate before the first Stage observes/acknowledges any physical row.
if ($variation) {
    Probe::$db->exec("UPDATE owned_woocommerce_order_itemmeta SET meta_value='44' WHERE order_item_id=456 AND meta_key='_variation_id'");
}
$result = $stager->stage($order, $p, static fn (): bool => true);
$p = $result['prepared']->mark_complete($result['stage'], 1);
if ($p->state() !== 'complete') {
    throw new RuntimeException('Owned first writer did not complete.');
}
$expectedPacket = $result['stage']->packets()[456]->private_json();

// Model only the native hydration result, never a write or a repair to SQL.
if ($mode !== 'untouched') {
    if ($mode !== 'variation_deleted') {
        $setCoordinate($item, 'product_id', 0);
    }
    if ($variation && $mode !== 'product_deleted') {
        $setCoordinate($item, 'variation_id', 0);
    }
}
if ($mode === 'wrong_positive') {
    $setCoordinate($item, 'product_id', 34);
} elseif ($mode === 'dirty_zero' || $mode === 'dirty_equal_expected') {
    // The declared getter overlay mirrors WC_Data's pending-change read:
    // original33→pending0, or original0→pending33 (even the expected ID).
    $setCoordinate($item, 'product_id', $mode === 'dirty_zero' ? 33 : 0);
    $changes = $rawProperty($item, 'changes');
    $changes['product_id'] = $mode === 'dirty_zero' ? 0 : 33;
    $setProperty($item, 'changes', $changes);
    $pending = $changes['product_id'];
    $GLOBALS['r24_history_coordinate_filter'] = static function (WC_Order_Item_Product $item, string $field, mixed $value) use ($pending): mixed {
        return $field === 'product_id' ? $pending : $value;
    };
} elseif ($mode === 'filtered_zero') {
    $setCoordinate($item, 'product_id', 33);
    $GLOBALS['r24_history_coordinate_filter'] = static function (WC_Order_Item_Product $item, string $field, mixed $value): mixed {
        return $field === 'product_id' ? 0 : $value;
    };
} elseif ($mode === 'raw_original_string') {
    $setCoordinate($item, 'product_id', '0');
}

if (in_array($mode, ['physical_product_duplicate', 'physical_variation_duplicate'], true)) {
    $key = $mode === 'physical_product_duplicate' ? '_product_id' : '_variation_id';
    $query = Probe::$db->prepare('INSERT INTO owned_woocommerce_order_itemmeta(order_item_id,meta_key,meta_value) SELECT order_item_id,meta_key,meta_value FROM owned_woocommerce_order_itemmeta WHERE order_item_id=456 AND meta_key=?');
    $query->execute([$key]);
}
$physicalChanges = [
    'physical_wrong_product' => ['_product_id', '34'],
    'physical_wrong_variation' => ['_variation_id', '45'],
    'physical_noncanonical' => ['_product_id', '033'],
    'physical_malformed' => ['_product_id', '33x'],
    'physical_null' => ['_product_id', null],
    'physical_legacy_changed' => [OrderDeliverySnapshot::META_LINE_SNAPSHOT, $rawline . ' '],
    'physical_legacy_malformed' => [OrderDeliverySnapshot::META_LINE_SNAPSHOT, '{'],
    'physical_packet_changed' => [PolicyNativeOrderFacts::META_PACKET, $expectedPacket . ' '],
];
if (isset($physicalChanges[$mode])) {
    [$key, $value] = $physicalChanges[$mode];
    $query = Probe::$db->prepare('UPDATE owned_woocommerce_order_itemmeta SET meta_value=? WHERE order_item_id=456 AND meta_key=?');
    $query->execute([$value, $key]);
}

$physical = static function () use ($schemas): string {
    $rows = [];
    foreach (array_keys($schemas) as $table) {
        $rows[$table] = Probe::$db->query('SELECT * FROM owned_' . $table . ' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', serialize($rows));
};
$before = $physical();
$beforeNative = $rawProperty($item, 'data');
$writes = Probe::$writes;
$sourceReads = $source_reads;
$reloads = Probe::$reloads;
$retirements = Probe::$retirements;
$late = false;
$authorityInSql = 0;
$authorize = static function () use ($mode, $order, $item, $setCoordinate, $retirements, &$late, &$authorityInSql): bool {
    if (Probe::$owned) {
        ++$authorityInSql;
        throw new LogicException('Owned authority called inside SQL.');
    }
    if (!$late && str_starts_with($mode, 'late_') && Probe::$retirements > $retirements) {
        $late = true;
        if ($mode === 'late_money') {
            $order->mutation();
        } elseif ($mode === 'late_packet') {
            $item->replace(PolicyNativeOrderFacts::META_PACKET, 'late unsaved packet');
        } elseif ($mode === 'late_coordinate') {
            $setCoordinate($item, 'product_id', 33);
        } elseif ($mode === 'late_site') {
            $GLOBALS['blog_id'] = 2;
        } elseif ($mode === 'late_deny') {
            return false;
        }
    }
    return true;
};
$reader = new PolicySavedOrderReader(new Factory());
$read = false;
$packetExact = false;
$error = null;
try {
    $packets = $reader->read($order, $authorize);
    $read = is_array($packets) && count($packets) === 1;
    $packetExact = $read && $packets[456]->private_json() === $expectedPacket;
} catch (Throwable $exception) {
    $error = get_class($exception);
}
$proof = false;
try {
    $proof = $reader->proof($order, static fn (): bool => true) instanceof CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStage;
} catch (Throwable) {
}
$afterNative = $rawProperty($item, 'data');
$physicalIds = Probe::$db->query("SELECT meta_key,meta_value FROM owned_woocommerce_order_itemmeta WHERE order_item_id=456 AND meta_key IN ('_product_id','_variation_id') ORDER BY meta_id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode([
    'first_writer_complete' => $p->state() === 'complete',
    'read' => $read,
    'packet_exact' => $packetExact,
    'strict_proof' => $proof,
    'native_before' => ['product_id' => $beforeNative['product_id'], 'variation_id' => $beforeNative['variation_id']],
    'native_after' => ['product_id' => $afterNative['product_id'], 'variation_id' => $afterNative['variation_id']],
    'physical_ids' => $physicalIds,
    'physical_unchanged' => $physical() === $before,
    'writes' => Probe::$writes - $writes,
    'source_reads' => $source_reads - $sourceReads,
    'metadata_reloads' => Probe::$reloads - $reloads,
    'getters_in_sql' => Probe::$getters,
    'authority_in_sql' => $authorityInSql,
    'late_authority_reached' => $late,
    'error' => $error,
    'physical_qualification' => false,
], JSON_THROW_ON_ERROR);
