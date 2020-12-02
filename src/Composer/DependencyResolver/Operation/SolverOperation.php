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

namespace Composer\DependencyResolver\Operation;

/**
 * Abstract operation class.
 *
 * @author Aleksandr Bezpiatov <aleksandr.bezpiatov@spryker.com>
 */
abstract class SolverOperation implements OperationInterface
{
    const TYPE = null;

    /**
     * Returns operation type.
     *
     * @return string
     */
    public function getOperationType()
    {
        return static::TYPE;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        return $this->show(false);
    }
}
