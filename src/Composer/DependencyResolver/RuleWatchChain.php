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
 * An extension of SplDoublyLinkedList with seek and removal of current element
 *
 * SplDoublyLinkedList only allows deleting a particular offset and has no
 * method to set the internal iterator to a particular offset.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @extends \SplDoublyLinkedList<RuleWatchNode>
 */
class RuleWatchChain extends \SplDoublyLinkedList
{
    /**
     * Moves the internal iterator to the specified offset
     *
     * @param int $offset The offset to seek to.
     */
    public function seek(int $offset): void
    {
        $this->rewind();
        for ($i = 0; $i < $offset; $i++, $this->next());
    }

    /**
     * Removes the current element from the list
     *
     * As SplDoublyLinkedList only allows deleting a particular offset and
     * incorrectly sets the internal iterator if you delete the current value
     * this method sets the internal iterator back to the following element
     * using the seek method.
     */
    public function remove(): void
    {
        $offset = $this->key();
        $this->offsetUnset($offset);
        $this->seek($offset);
    }
}
