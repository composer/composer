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

/**
 * Interface for graph output modules that are
 * responsible for drawing a given directed graph
 * from array form.
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
interface GraphOutputInterface
{
    /**
     * Draws the specified directed dependency graph,
     * given in the form of:
     *
     * [
     *  ['source' => 'ea/guzzle-bundle', 'target' => 'symfony/symfony', 'type' => 'require'],
     *  ['source' => 'symfony/symfony', 'target' => 'twig/twig', 'type' => 'require']
     * ]
     *
     * @param array  $graph A graph in the form specified above
     * @return string The graphing module's output
     */
    public function draw(array $graph);
}
