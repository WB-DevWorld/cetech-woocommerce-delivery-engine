<?php
declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Genuine first writer and saved reader over declared native/SQLite ports. */
final class PolicyDeletedCatalogHistoryWriterTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_deleted_native_coordinates_only_yield_physically_acknowledged_history_packets(string $backend, string $mode, bool $accepted): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/CustomerPolicy/policy-deleted-catalog-history-writer-probe.php', $backend, $mode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . ' ' . $output);
        $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);

        self::assertTrue($result['first_writer_complete']);
        self::assertFalse($result['physical_qualification']);
        self::assertSame($accepted, $result['read']);
        self::assertSame($accepted, $result['packet_exact']);
        // The read path returns only immutable packets. Deleted coordinate
        // tolerance is never inherited by saved payment/order proof.
        self::assertSame($mode === 'untouched', $result['strict_proof']);
        self::assertTrue($result['physical_unchanged']);
        foreach (['writes', 'source_reads', 'metadata_reloads', 'getters_in_sql', 'authority_in_sql'] as $field) {
            self::assertSame(0, $result[$field], $field);
        }
        self::assertSame(str_starts_with($mode, 'late_'), $result['late_authority_reached']);
        if ($accepted) {
            self::assertNull($result['error']);
            $coordinates = match ($mode) {
                'untouched' => ['product_id' => 33, 'variation_id' => 44],
                'product_deleted' => ['product_id' => 0, 'variation_id' => 44],
                'variation_deleted' => ['product_id' => 33, 'variation_id' => 0],
                default => ['product_id' => 0, 'variation_id' => 0],
            };
            self::assertSame($coordinates, $result['native_before']);
            self::assertSame($coordinates, $result['native_after']);
            self::assertSame([
                ['meta_key' => '_product_id', 'meta_value' => '33'],
                ['meta_key' => '_variation_id', 'meta_value' => $mode === 'simple_deleted' ? '0' : '44'],
            ], $result['physical_ids']);
        } else {
            $physicalCoordinateRefusal = in_array($mode, ['physical_product_duplicate', 'physical_variation_duplicate', 'physical_wrong_product', 'physical_wrong_variation', 'physical_noncanonical', 'physical_malformed'], true);
            self::assertSame($physicalCoordinateRefusal ? \UnexpectedValueException::class : \RuntimeException::class, $result['error']);
        }
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['hpos', 'cpt'] as $backend) {
            foreach (['untouched', 'simple_deleted', 'product_deleted', 'variation_deleted', 'both_deleted'] as $mode) {
                $cases[$backend . ':' . $mode] = [$backend, $mode, true];
            }
            foreach (['wrong_positive', 'dirty_zero', 'dirty_equal_expected', 'filtered_zero', 'raw_original_string', 'physical_product_duplicate', 'physical_variation_duplicate', 'physical_wrong_product', 'physical_wrong_variation', 'physical_noncanonical', 'physical_malformed', 'physical_null', 'physical_legacy_changed', 'physical_legacy_malformed', 'physical_packet_changed', 'late_money', 'late_packet', 'late_coordinate', 'late_site', 'late_deny'] as $mode) {
                $cases[$backend . ':' . $mode] = [$backend, $mode, false];
            }
        }
        return $cases;
    }
}
