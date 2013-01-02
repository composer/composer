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

use Composer\Grapher\GraphOutputInterface;

/**
 * Graph output module for d3.js.
 *
 * @author Felix Jodoin <felix@fjstudios.net>
 */
class D3GraphOutput implements GraphOutputInterface
{
    /**
     * {@inheritDoc}
     */
    public function draw(array $graph)
    {
        // encode the graph as JSON and output it before the template page
        $json = json_encode($graph);

        $head =<<<HERE
<script type="text/javascript">
var graph = $json;
</script>

HERE
        ;

        $target = '{{ data }}';

        $templateFile = __DIR__ . '/res/index.html';
        $originalTemplate = file_get_contents($templateFile);
        $renderedTemplate = str_replace($target, $head, $originalTemplate);

        return $renderedTemplate;
    }
}
