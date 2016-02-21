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
     * @return string The graphing module's output
     */
    public function graph()
    {
        return $this->output->draw($this->builder->build());
    }
}
