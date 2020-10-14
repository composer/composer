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

use Composer\Package\PackageInterface;

/**
 * Abstract operation class.
 *
 * @author Aleksandr Bezpiatov <aleksandr.bezpiatov@spryker.com>
 */
abstract class Operation implements OperationInterface
{
    const TYPE = null;

    /**
     * @var PackageInterface
     */
    protected $package;

    /**
     * Initializes operation.
     *
     * @param PackageInterface $package package instance
     */
    public function __construct(PackageInterface $package)
    {
        $this->package = $package;
    }

    /**
     * Returns package instance.
     *
     * @return PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

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
