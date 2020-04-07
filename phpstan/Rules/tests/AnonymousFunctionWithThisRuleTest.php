<?php declare(strict_types = 1);

namespace Composer\PHPStanRulesTests;

use Composer\PHPStanRules\AnonymousFunctionWithThisRule;
use PHPStan\Testing\RuleTestCase;

/**
 * @phpstan-extends RuleTestCase<AnonymousFunctionWithThisRule>
 */
final class AnonymousFunctionWithThisRuleTest extends RuleTestCase
{
    /**
     * @inheritDoc
     */
    protected function getRule(): \PHPStan\Rules\Rule
    {
        return new AnonymousFunctionWithThisRule();
    }

    public function testWithThis(): void
    {
        $this->analyse([__DIR__ . '/data/method-with-this.php'], [
            ['Using $this inside anonymous function is prohibited because of PHP 5.3 support.', 13],
            ['Using $this inside anonymous function is prohibited because of PHP 5.3 support.', 17],
        ]);
    }
}
