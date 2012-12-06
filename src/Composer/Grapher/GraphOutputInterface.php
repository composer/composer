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
   *  ['source' => 'ea/guzzle-bundle', 'target' => 'symfony/symfony', 'type' => 'require'],
   *  ['source' => 'symfony/symfony', 'target' => 'twig/twig', 'type' => 'require']
   * ]
   *
   * @param array  $graph A graph in the form specified above
   * @return string The graphing module's output
   */
  public function draw(array $graph);
}