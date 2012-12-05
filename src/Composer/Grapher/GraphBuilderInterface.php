<?php

namespace Composer\Grapher;

/**
 * Interface for various graph builders to implement.
 * For instance, creating a dependency chart using Locker
 * would requisite a LockerGraphBuilder.
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
interface GraphBuilderInterface
{
  /**
   * Function to build (or return a cached version of) a directed graph.
   * Should return an array specifying each vertex for all directions desired,
   * as well as the type of dependency (e.g. requires)
   *
   * Example output:
   *
   * [
   *  ['source' => 'ea/guzzle-bundle', 'destination' => 'symfony/symfony', 'type' => 'requires'],
   *  ['source' => 'symfony/symfony', 'destination' => 'twig/twig', 'type' => 'requires']
   * ]
   *
   * This would specify a graph of 3 edges (ea/guzzle-bundle, symfony/symfony, 
   * and twig/twig), where ea/guzzle-bundle depends on symfony/symfony, which
   * in turn depends on twig/twig.
   *
   * @return array See example output
   */
  public function build();
}