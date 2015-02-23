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

namespace Composer\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class HtmlOutputFormatter extends OutputFormatter
{
    private static $availableForegroundColors = array(
        30 => 'black',
        31 => 'red',
        32 => 'green',
        33 => 'yellow',
        34 => 'blue',
        35 => 'magenta',
        36 => 'cyan',
        37 => 'white'
    );
    private static $availableBackgroundColors = array(
        40 => 'black',
        41 => 'red',
        42 => 'green',
        43 => 'yellow',
        44 => 'blue',
        45 => 'magenta',
        46 => 'cyan',
        47 => 'white'
    );
    private static $availableOptions = array(
        1 => 'bold',
        4 => 'underscore',
        //5 => 'blink',
        //7 => 'reverse',
        //8 => 'conceal'
    );

    /**
     * @param array $styles Array of "name => FormatterStyle" instances
     */
    public function __construct(array $styles = array())
    {
        parent::__construct(true, $styles);
    }

    public function format($message)
    {
        $formatted = parent::format($message);

        return preg_replace_callback("{\033\[([0-9;]+)m(.*?)\033\[0m}s", array($this, 'formatHtml'), $formatted);
    }

    private function formatHtml($matches)
    {
        $out = '<span style="';
        foreach (explode(';', $matches[1]) as $code) {
            if (isset(self::$availableForegroundColors[$code])) {
                $out .= 'color:'.self::$availableForegroundColors[$code].';';
            } elseif (isset(self::$availableBackgroundColors[$code])) {
                $out .= 'background-color:'.self::$availableBackgroundColors[$code].';';
            } elseif (isset(self::$availableOptions[$code])) {
                switch (self::$availableOptions[$code]) {
                    case 'bold':
                        $out .= 'font-weight:bold;';
                        break;

                    case 'underscore':
                        $out .= 'text-decoration:underline;';
                        break;
                }
            }
        }

        return $out.'">'.$matches[2].'</span>';
    }
}
