<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Hash;

use Padosoft\PatentBoxTracker\Hash\HashChainBuilder;
use PHPUnit\Framework\TestCase;

final class HashChainBuilderTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SHA_C = 'cccccccccccccccccccccccccccccccccccccccc';

    public function test_link_uses_colon_separator_to_block_prefix_collisions(): void
    {
        $builder = new HashChainBuilder;

        $direct = $builder->link('abc', 'def');
        $expected = hash('sha256', 'abc:def');

        $this->assertSame($expected, $direct);

        // Trivial prefix-collision attempt: without a separator,
        // (prev='abcde', sha='f') would concatenate to 'abcdef',
        // colliding with (prev='abc', sha='def'). The colon
        // separator removes the collision.
        $alt = $builder->link('abcde', 'f');
        $this->assertNotSame($direct, $alt);
    }

    public function test_link_treats_null_prev_as_empty_string(): void
    {
        $builder = new HashChainBuilder;

        $self = $builder->link(null, self::SHA_A);
        $this->assertSame(hash('sha256', ':'.self::SHA_A), $self);
    }

    public function test_chain_is_deterministic_for_same_input(): void
    {
        $builder = new HashChainBuilder;

        $first = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);
        $second = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);

        $this->assertSame($first, $second);
    }

    public function test_chain_links_each_row_to_the_previous_self(): void
    {
        $builder = new HashChainBuilder;

        $manifest = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);

        $this->assertCount(3, $manifest);
        $this->assertNull($manifest[0]['prev']);
        $this->assertSame($manifest[0]['self'], $manifest[1]['prev']);
        $this->assertSame($manifest[1]['self'], $manifest[2]['prev']);
    }

    public function test_chain_returns_empty_array_for_empty_input(): void
    {
        $builder = new HashChainBuilder;

        $this->assertSame([], $builder->chain([]));
    }

    public function test_verify_passes_for_unmodified_chain(): void
    {
        $builder = new HashChainBuilder;

        $manifest = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);
        $this->assertTrue($builder->verify($manifest));
    }

    public function test_verify_passes_for_empty_manifest(): void
    {
        $builder = new HashChainBuilder;

        $this->assertTrue($builder->verify([]));
    }

    public function test_verify_breaks_when_a_commit_sha_is_tampered(): void
    {
        $builder = new HashChainBuilder;

        $manifest = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);
        $manifest[1]['sha'] = str_repeat('9', 40);

        $this->assertFalse($builder->verify($manifest));
    }

    public function test_verify_breaks_when_a_self_hash_is_tampered(): void
    {
        $builder = new HashChainBuilder;

        $manifest = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);
        $manifest[2]['self'] = str_repeat('0', 64);

        $this->assertFalse($builder->verify($manifest));
    }

    public function test_verify_breaks_when_a_prev_pointer_is_tampered(): void
    {
        $builder = new HashChainBuilder;

        $manifest = $builder->chain([self::SHA_A, self::SHA_B, self::SHA_C]);
        $manifest[2]['prev'] = str_repeat('1', 64);

        $this->assertFalse($builder->verify($manifest));
    }

    public function test_chain_runs_one_thousand_rows_under_one_hundred_milliseconds(): void
    {
        $builder = new HashChainBuilder;

        $shas = [];
        for ($i = 0; $i < 1000; $i++) {
            $shas[] = str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
        }

        $start = microtime(true);
        $manifest = $builder->chain($shas);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertCount(1000, $manifest);
        $this->assertLessThan(
            100.0,
            $elapsedMs,
            sprintf('1000-row chain should complete in <100ms, took %.2fms.', $elapsedMs),
        );
        $this->assertTrue($builder->verify($manifest));
    }
}
