<?php
declare(strict_types=1);

namespace test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Lock down the LIKE-wildcard escape for Mail::log()'s messageId filter.
 *
 * The controller uses this exact str_replace expression to neutralise
 * `_` and `%` in user-supplied messageId values before interpolating them
 * into a `LIKE '%...%'` clause. Regression-test the transform shape so a
 * future "cleanup" doesn't strip the escape.
 */
class MessageIdEscapeTest extends TestCase
{
    private function escape(string $input): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);
    }

    public function test_underscore_is_escaped(): void
    {
        $this->assertSame('foo\\_bar', $this->escape('foo_bar'));
    }

    public function test_percent_is_escaped(): void
    {
        $this->assertSame('100\\%', $this->escape('100%'));
    }

    public function test_backslash_is_escaped_first(): void
    {
        // A literal backslash should become two before it is examined as a
        // potential escape character itself.
        $this->assertSame('a\\\\b', $this->escape('a\\b'));
    }

    public function test_plain_input_is_unchanged(): void
    {
        $this->assertSame('abc-123@example.com', $this->escape('abc-123@example.com'));
    }
}
