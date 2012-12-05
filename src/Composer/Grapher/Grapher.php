<?php

namespace Composer\Grapher;

use Composer\Grapher\GraphBuilderInterface;
use Composer\Grapher\GraphOutputInterface;

/**
 * Graphs a set of dependencies retrieved from the given
 * input frontend using the given graphing backend (for
 * example, LockerGraphBuilder and D3GraphOutput).
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
class Grapher
{
  /**
   * @var GraphBuilderInterface
   **/
  private $builder;

  /**
   * @var GraphOutputInterface
   **/
  private $output;

  /**
   * Constructor
   *
   * @param GraphBuilderInterface $builder
   * @param GraphOutputInterface  $output
   */
  public function __construct(GraphBuilderInterface $builder, GraphOutputInterface $output)
  {
    $this->builder = $builder;
    $this->output = $output;
  }

  /**
   * Draws the graph using the builder and output modules passed into the constructor.
   *
   * @param string $path  A string representing the location of output. Might not be 
   *                      necessary for some output modules. 
   */
  public function graph($path)
  {
    $this->output->draw($this->builder->build(), $path);
  }
}