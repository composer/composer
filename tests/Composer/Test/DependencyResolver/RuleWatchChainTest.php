<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\RuleWatchChain;
use Composer\DependencyResolver\RuleWatchNode;
use Composer\Test\TestCase;

class RuleWatchChainTest extends TestCase
{
    /**
     * @dataProvider provideRemovalPositions
     */
    public function testRemovePreservesIteratorPosition(int $size, int $mode, int $offset, int $remaining, int $key, ?int $current): void
    {
        $chain = new RuleWatchChain;
        $nodes = [];
        for ($i = 0; $i < $size; ++$i) {
            $nodes[$i] = new RuleWatchNode(new GenericRule([$i + 1], Rule::RULE_LEARNED, 0));
            $chain->push($nodes[$i]);
        }
        $chain->setIteratorMode($mode);
        $chain->seek($offset);

        $chain->remove();

        self::assertCount($remaining, $chain);
        self::assertSame($key, $chain->key());
        self::assertSame($current !== null, $chain->valid());
        self::assertSame($current !== null ? $nodes[$current] : null, $chain->current());
    }

    public static function provideRemovalPositions(): array
    {
        return [
            'only element' => [1, RuleWatchChain::IT_MODE_FIFO, 0, 0, 0, null],
            'head' => [4, RuleWatchChain::IT_MODE_FIFO, 0, 3, 0, 1],
            'middle' => [4, RuleWatchChain::IT_MODE_FIFO, 1, 3, 1, 2],
            'before tail' => [4, RuleWatchChain::IT_MODE_FIFO, 2, 3, 2, 3],
            'tail' => [4, RuleWatchChain::IT_MODE_FIFO, 3, 3, 3, null],
            'delete mode' => [4, RuleWatchChain::IT_MODE_DELETE, 1, 2, 0, 2],
            'lifo mode' => [4, RuleWatchChain::IT_MODE_LIFO, 1, 3, 0, 0],
            'lifo delete mode' => [4, RuleWatchChain::IT_MODE_LIFO | RuleWatchChain::IT_MODE_DELETE, 1, 0, -1, null],
        ];
    }

    public function testRemoveConsecutiveNodesAfterRetainedPrefix(): void
    {
        $chain = new RuleWatchChain;
        $nodes = [];
        for ($i = 0; $i < 5; ++$i) {
            $nodes[$i] = new RuleWatchNode(new GenericRule([$i + 1], Rule::RULE_LEARNED, 0));
            $chain->push($nodes[$i]);
        }
        $chain->seek(1);

        for ($i = 1; $i < 5; ++$i) {
            self::assertSame($nodes[$i], $chain->current());
            self::assertSame(1, $chain->key());
            $chain->remove();
        }

        self::assertCount(1, $chain);
        self::assertFalse($chain->valid());
        $chain->rewind();
        self::assertSame($nodes[0], $chain->current());
    }
}
