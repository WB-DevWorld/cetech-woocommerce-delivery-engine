<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual typed saved proofs; declared native-shaped and in-memory SQL ports. */
final class PolicyAcknowledgedFinalAuthorityTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_final_authority_cannot_return_a_stale_native_proof_or_saved_packets(string $surface, string $mutation, string $backend): void
    {
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/CustomerPolicy/policy-ack-final-authority-probe.php',
            $surface, $mutation, $backend,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . ' ' . $stdout);
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        self::assertFalse($result['physical_qualification']);
        self::assertTrue($result['late_authority_reached']);
        self::assertSame($surface === 'read' ? 4 : 3, $result['authority_calls']);
        self::assertSame(0, $result['authority_calls_in_sql']);
        self::assertSame(0, $result['getters_in_sql']);
        self::assertSame(1, $result['retired']);
        self::assertSame(0, $result['writes']);
        self::assertTrue($result['physical_unchanged']);
        self::assertSame($mutation === 'untouched', $result['returned']);
        if ($mutation === 'untouched') {
            self::assertSame(1, $result['packets']);
            self::assertNull($result['error']);
            if ($surface === 'proof') {
                self::assertTrue($result['typed_stage']);
                self::assertTrue($result['unchanged']);
            }
        } else {
            self::assertSame('RuntimeException', $result['error']);
        }
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (['hpos', 'cpt'] as $backend) {
            foreach (['proof', 'read'] as $surface) {
                foreach (['untouched', 'deny', 'money', 'packet', 'site'] as $mutation) {
                    $cases[$backend . ' ' . $surface . ' ' . $mutation] = [$surface, $mutation, $backend];
                }
            }
        }
        return $cases;
    }
}
