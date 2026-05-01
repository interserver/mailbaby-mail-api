<?php
declare(strict_types=1);

namespace test\Unit\Mail;

use app\controller\Mail\Stats;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stats::fromTimestamp(). It is the only piece of Stats
 * that does pure value-in / value-out work without hitting databases.
 */
class StatsTest extends TestCase
{
    private Stats $stats;

    protected function setUp(): void
    {
        $this->stats = new Stats();
    }

    public function test_parses_iso_like_datetime(): void
    {
        $expected = mktime(13, 45, 30, 5, 1, 2026);
        $this->assertSame($expected, $this->stats->fromTimestamp('2026-05-01 13:45:30'));
    }

    public function test_parses_compact_yyyymmddhhmmss(): void
    {
        $expected = mktime(13, 45, 30, 5, 1, 2026);
        $this->assertSame($expected, $this->stats->fromTimestamp('20260501134530'));
    }

    public function test_parses_yyyymmdd_with_default_time(): void
    {
        $expected = mktime(1, 1, 1, 5, 1, 2026);
        $this->assertSame($expected, $this->stats->fromTimestamp('20260501'));
    }

    public function test_passes_through_numeric_unix_timestamp(): void
    {
        $ts = 1900000000; // somewhere in 2030
        $this->assertSame($ts, $this->stats->fromTimestamp($ts));
    }

    public function test_returns_false_for_unparseable_input(): void
    {
        $this->assertFalse($this->stats->fromTimestamp('not a date'));
        $this->assertFalse($this->stats->fromTimestamp(''));
    }

    public function test_returns_false_for_pre_epoch_floor_numeric(): void
    {
        // Anything below 943938000 (Nov 1999) is rejected.
        $this->assertFalse($this->stats->fromTimestamp(0));
        $this->assertFalse($this->stats->fromTimestamp(123456));
    }
}
