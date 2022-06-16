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

namespace Composer\Installer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\Event;
use Composer\IO\IOInterface;
use Composer\Repository\RepositoryInterface;

/**
 * The Package Event.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageEvent extends Event
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var bool
     */
    private $devMode;

    /**
     * @var RepositoryInterface
     */
    private $localRepo;

    /**
     * @var OperationInterface[]
     */
    private $operations;

    /**
     * @var OperationInterface The operation instance which is being executed
     */
    private $operation;

    /**
     * Constructor.
     *
     * @param string               $eventName
     * @param Composer             $composer
     * @param IOInterface          $io
     * @param bool                 $devMode
     * @param RepositoryInterface  $localRepo
     * @param OperationInterface[] $operations
     * @param OperationInterface   $operation
     */
    public function __construct(string $eventName, Composer $composer, IOInterface $io, bool $devMode, RepositoryInterface $localRepo, array $operations, OperationInterface $operation)
    {
        parent::__construct($eventName);

        $this->composer = $composer;
        $this->io = $io;
        $this->devMode = $devMode;
        $this->localRepo = $localRepo;
        $this->operations = $operations;
        $this->operation = $operation;
    }

    /**
     * @return Composer
     */
    public function getComposer(): Composer
    {
        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    /**
     * @return bool
     */
    public function isDevMode(): bool
    {
        return $this->devMode;
    }

    /**
     * @return RepositoryInterface
     */
    public function getLocalRepo(): RepositoryInterface
    {
        return $this->localRepo;
    }

    /**
     * @return OperationInterface[]
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * Returns the package instance.
     *
     * @return OperationInterface
     */
    public function getOperation(): OperationInterface
    {
        return $this->operation;
    }
}
