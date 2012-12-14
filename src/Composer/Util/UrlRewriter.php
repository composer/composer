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

namespace Composer\Util;

/**
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class UrlRewriter
{
    private $rules;

    /**
     * Constructor.
     *
     * @param array $rules The rewrite rules
     */
    public function __construct($rules = array())
    {
        $this->rules = (array) $rules;
    }

    /**
     * Rewrites URL
     *
     * @param string $url A URL
     *
     * @return string
     */
    public function rewrite($url)
    {
        foreach ($this->rules as $pattern => $replacement) {
            $url = preg_replace('{'.$pattern.'}', $replacement, $url);
        }

        return $url;
    }
}
