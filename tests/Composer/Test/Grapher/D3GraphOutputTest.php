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

namespace Composer\Test\Grapher;

use Composer\Grapher\D3GraphOutput;
use Composer\Test\TestCase;

class D3GraphOutputTest extends TestCase
{
    protected $output;

    public function setUp()
    {
        $this->output = new D3GraphOutput;
    }

    public function testGraphOutput()
    {
        $fixture = file_get_contents(__DIR__.'/Fixtures/many-to-many-dependencies.json');
        $graph = json_decode($fixture, true);

        $result = $this->output->draw($graph);
        
        $this->assertTrue(strpos($result, json_encode($graph)) !== FALSE,
            "contains the JSON representation of the graph");
        $this->assertTrue(strpos($result, "<!DOCTYPE html>") !== FALSE,
            "contains the HTML template");
    }
}
