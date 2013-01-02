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

namespace Composer\Grapher;

use Composer\Grapher\GraphBuilderInterface;
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

        /*
         Go through every package once and enumerate its dependencies.
         Required packages all have their own entries, so recursion has
         already been handled in the lockfile.
         */
        $this->repository->filterPackages(function ($package) use (&$graph) {
            $requirements = $package->getRequires();

            /*
            Every requirement is a vertex in the graph.
            */
            foreach ($requirements as $requirement) {
                $vertex = array();

                $vertex['source'] = $requirement->getSource();
                $vertex['target'] = $requirement->getTarget();
                $vertex['type'] = 'requires';

                $graph[] = $vertex;
            }
        });

        return $graph;
    }
}
