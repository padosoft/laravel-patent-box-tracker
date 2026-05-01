<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Classifier;

use InvalidArgumentException;
use Padosoft\PatentBoxTracker\Classifier\Phase;
use PHPUnit\Framework\TestCase;

final class PhaseTest extends TestCase
{
    public function test_six_cases_exist_with_canonical_string_values(): void
    {
        $values = array_map(static fn (Phase $p): string => $p->value, Phase::cases());

        $this->assertSame(
            ['research', 'design', 'implementation', 'validation', 'documentation', 'non_qualified'],
            $values,
        );
    }

    public function test_from_string_resolves_each_canonical_value(): void
    {
        foreach (Phase::cases() as $expected) {
            $resolved = Phase::fromString($expected->value);
            $this->assertSame($expected, $resolved);
        }
    }

    public function test_from_string_throws_on_unknown_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid value/');

        Phase::fromString('unknown_phase');
    }

    public function test_try_from_string_returns_null_on_unknown_value(): void
    {
        $this->assertNull(Phase::tryFromString('unknown_phase'));
        $this->assertNull(Phase::tryFromString(''));
        $this->assertNull(Phase::tryFromString('NON_QUALIFIED')); // strict case sensitivity
    }

    public function test_qualifies_returns_false_only_for_non_qualified(): void
    {
        $this->assertTrue(Phase::Research->qualifies());
        $this->assertTrue(Phase::Design->qualifies());
        $this->assertTrue(Phase::Implementation->qualifies());
        $this->assertTrue(Phase::Validation->qualifies());
        $this->assertTrue(Phase::Documentation->qualifies());
        $this->assertFalse(Phase::NonQualified->qualifies());
    }
}
