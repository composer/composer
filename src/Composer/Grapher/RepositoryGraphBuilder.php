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
    $graph = array();
    $packages = $this->repository->getPackages();

    /*
     Go through every package once and enumerate its dependencies.
     Required packages all have their own entries, so recursion has
     already been handled in the lockfile.
     */
    foreach($packages as $package)
    {
      $requirements = $package->getRequires();

      /*
      Every requirement is a vertex in the graph.
      */
      foreach($requirements as $requirement)
      {
        $vertex = array();
        
        $vertex['source'] = $requirement->getSource();
        $vertex['target'] = $requirement->getTarget();
        $vertex['type'] = 'requires';

        $graph[] = $vertex;
      }
    }

    return $graph;
  }
}