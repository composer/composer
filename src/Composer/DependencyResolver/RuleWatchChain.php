<?php

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
 */
class RuleWatchChain extends \SplDoublyLinkedList
{
    protected $offset = 0;

    public function seek($offset)
    {
        $this->rewind();
        for ($i = 0; $i < $offset; $i++, $this->next());
    }

    public function remove()
    {
        $offset = $this->key();
        $this->offsetUnset($offset);
        $this->seek($offset);
    }
}
