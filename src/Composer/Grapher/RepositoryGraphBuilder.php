<?php

namespace Composer\Grapher;

use Composer\Grapher\GraphBuilderInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;

/**
 * Graph builder for cached lists of packages.
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
class RepositoryGraphBuilder implements GraphBuilderInterface
{
  /**
   * @var RepositoryInterface
   **/
  private $repository;

  /**
   * Constructor
   *
   * @param Repository $repository
   */
  public function __construct(RepositoryInterface $repository)
  {
    $this->repository = $repository;
  }

  /**
   * {@inheritDoc}
   */
  public function build()
  {
    $graph = [];
    $packages = $this->repository->getPackages();

    return $graph;
  }
}