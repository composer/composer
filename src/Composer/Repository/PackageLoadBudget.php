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

namespace Composer\Repository;

/**
 * Limits additional memory retained while metadata requests are pending
 *
 * Checks happen between documents, so one document can exceed the target
 */
final class PackageLoadBudget
{
    /** @var positive-int */
    private $maxRetainedBytes;

    public function __construct(int $maxRetainedBytes = 33554432)
    {
        if ($maxRetainedBytes < 1) {
            throw new \InvalidArgumentException('Package load memory budget must be positive');
        }
        $this->maxRetainedBytes = $maxRetainedBytes;
    }

    public function shouldFlush(int $retainedBytes): bool
    {
        return $retainedBytes >= $this->maxRetainedBytes;
    }
}
