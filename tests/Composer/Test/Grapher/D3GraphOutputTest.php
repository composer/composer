<?php

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

    $this->assertTrue(strpos($result, json_encode($graph, JSON_UNESCAPED_SLASHES)) !== FALSE, 
      "contains the JSON representation of the graph");
    $this->assertTrue(strpos($result, "<!DOCTYPE html>") !== FALSE,
      "contains the HTML template");
  }
}