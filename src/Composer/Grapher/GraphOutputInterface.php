<?php

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
   *  ['source' => 'ea/guzzle-bundle', 'destination' => 'symfony/symfony', 'type' => 'require'],
   *  ['source' => 'symfony/symfony', 'destination' => 'twig/twig', 'type' => 'require']
   * ]
   *
   * @param array  $graph A graph in the form specified above
   * @param string $path  A string representing the location of output. Might not be 
   *                      necessary for some modules. 
   */
  public function draw(array $graph, $path);
}