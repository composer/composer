<?php declare(strict_types=1);

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

use Closure;
use Composer\Pcre\Preg;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class HtmlOutputFormatter extends OutputFormatter
{
    /** @var array<int, string> */
    private static $availableForegroundColors = array(
        30 => 'black',
        31 => 'red',
        32 => 'green',
        33 => 'yellow',
        34 => 'blue',
        35 => 'magenta',
        36 => 'cyan',
        37 => 'white',
    );
    /** @var array<int, string> */
    private static $availableBackgroundColors = array(
        40 => 'black',
        41 => 'red',
        42 => 'green',
        43 => 'yellow',
        44 => 'blue',
        45 => 'magenta',
        46 => 'cyan',
        47 => 'white',
    );
    /** @var array<int, string> */
    private static $availableOptions = array(
        1 => 'bold',
        4 => 'underscore',
        //5 => 'blink',
        //7 => 'reverse',
        //8 => 'conceal'
    );

    /**
     * @param array<string, OutputFormatterStyle> $styles Array of "name => FormatterStyle" instances
     */
    public function __construct(array $styles = array())
    {
        parent::__construct(true, $styles);
    }

    public function format(?string $message): ?string
    {
        $formatted = parent::format($message);

        if ($formatted === null) {
            return null;
        }

        $clearEscapeCodes = '(?:39|49|0|22|24|25|27|28)';

        return Preg::replaceCallback("{\033\[([0-9;]+)m(.*?)\033\[(?:".$clearEscapeCodes.";)*?".$clearEscapeCodes."m}s", Closure::fromCallable([$this, 'formatHtml']), $formatted);
    }

    /**
     * @param string[] $matches
     */
    private function formatHtml(array $matches): string
    {
        $out = '<span style="';
        foreach (explode(';', $matches[1]) as $code) {
            if (isset(self::$availableForegroundColors[(int) $code])) {
                $out .= 'color:'.self::$availableForegroundColors[(int) $code].';';
            } elseif (isset(self::$availableBackgroundColors[(int) $code])) {
                $out .= 'background-color:'.self::$availableBackgroundColors[(int) $code].';';
            } elseif (isset(self::$availableOptions[(int) $code])) {
                switch (self::$availableOptions[(int) $code]) {
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
