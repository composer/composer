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

namespace Composer\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\PolicyInterface;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\Event;
use Composer\Repository\CompositeRepository;

/**
 * The Package Event.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageEvent extends InstallerEvent
{
    /**
     * @var OperationInterface The package instance
     */
    private $operation;

    /**
     * Constructor.
     *
     * @param string               $eventName
     * @param Composer             $composer
     * @param IOInterface          $io
     * @param bool                 $devMode
     * @param PolicyInterface      $policy
     * @param Pool                 $pool
     * @param CompositeRepository  $installedRepo
     * @param Request              $request
     * @param OperationInterface[] $operations
     * @param OperationInterface   $operation
     */
    public function __construct($eventName, Composer $composer, IOInterface $io, $devMode, PolicyInterface $policy, Pool $pool, CompositeRepository $installedRepo, Request $request, array $operations, OperationInterface $operation)
    {
        parent::__construct($eventName, $composer, $io, $devMode, $policy, $pool, $installedRepo, $request, $operations);

        $this->operation = $operation;
    }

    /**
     * Returns the package instance.
     *
     * @return OperationInterface
     */
    public function getOperation()
    {
        return $this->operation;
    }
}
