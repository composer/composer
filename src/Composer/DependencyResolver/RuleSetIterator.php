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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @implements \Iterator<RuleSet::TYPE_*, Rule>
 */
class RuleSetIterator implements \Iterator
{
    /** @var array<RuleSet::TYPE_*, Rule[]> */
    protected $rules;
    /** @var array<RuleSet::TYPE_*> */
    protected $types;

    /** @var int */
    protected $currentOffset;
    /** @var RuleSet::TYPE_*|-1 */
    protected $currentType;
    /** @var int */
    protected $currentTypeOffset;

    /**
     * @param array<RuleSet::TYPE_*, Rule[]> $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
        $this->types = array_keys($rules);
        sort($this->types);

        $this->rewind();
    }

    public function current(): Rule
    {
        return $this->rules[$this->currentType][$this->currentOffset];
    }

    /**
     * @return RuleSet::TYPE_*|-1
     */
    public function key(): int
    {
        return $this->currentType;
    }

    public function next(): void
    {
        ++$this->currentOffset;

        if (!isset($this->rules[$this->currentType])) {
            return;
        }

        if ($this->currentOffset >= \count($this->rules[$this->currentType])) {
            $this->currentOffset = 0;

            do {
                ++$this->currentTypeOffset;

                if (!isset($this->types[$this->currentTypeOffset])) {
                    $this->currentType = -1;
                    break;
                }

                $this->currentType = $this->types[$this->currentTypeOffset];
            } while (0 === \count($this->rules[$this->currentType]));
        }
    }

    public function rewind(): void
    {
        $this->currentOffset = 0;

        $this->currentTypeOffset = -1;
        $this->currentType = -1;

        do {
            ++$this->currentTypeOffset;

            if (!isset($this->types[$this->currentTypeOffset])) {
                $this->currentType = -1;
                break;
            }

            $this->currentType = $this->types[$this->currentTypeOffset];
        } while (0 === \count($this->rules[$this->currentType]));
    }

    public function valid(): bool
    {
        return isset($this->rules[$this->currentType], $this->rules[$this->currentType][$this->currentOffset]);
    }
}
