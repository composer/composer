<?php

namespace Composer\Autoload;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @internal
 */
class PhpFileCleaner
{
    /** @var array<array{name: string, length: int, pattern: string}> */
    private static $typeConfig;
    /** @var string */
    private static $restPattern;

    /**
     * @readonly
     * @var string
     */
    private $contents;

    /**
     * @readonly
     * @var int
     */
    private $len;

    /**
     * @readonly
     * @var int
     */
    private $maxMatches;

    /** @var int */
    private $index = 0;

    public static function setTypeConfig($types)
    {
        foreach ($types as $type) {
            self::$typeConfig[$type[0]] = array(
                'name' => $type,
                'length' => \strlen($type),
                'pattern' => '{.\b(?<![\$:>])'.$type.'\s++[a-zA-Z_\x7f-\xff:][a-zA-Z0-9_\x7f-\xff:\-]*+}Ais',
            );
        }

        self::$restPattern = '{[^?"\'</'.implode('', array_keys(self::$typeConfig)).']+}A';
    }

    public function __construct($contents, $maxMatches)
    {
        $this->contents = $contents;
        $this->len = \strlen($this->contents);
        $this->maxMatches = $maxMatches;
    }

    public function clean()
    {
        $clean = '';

        while ($this->index < $this->len) {
            $this->skipToPhp();
            $clean .= '<?';

            while ($this->index < $this->len) {
                $char = $this->contents[$this->index];
                if ($char === '?' && $this->peek('>')) {
                    $clean .= '?>';
                    $this->index += 2;
                    continue 2;
                }

                if ($char === '"') {
                    $this->skipString('"');
                    $clean .= 'null';
                    continue;
                }

                if ($char === "'") {
                    $this->skipString("'");
                    $clean .= 'null';
                    continue;
                }

                if ($char === "<" && $this->peek('<') && $this->match('{<<<[ \t]*+([\'"]?)([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*+)\\1(?:\r\n|\n|\r)}A', $match)) {
                    $this->index += \strlen($match[0]);
                    $this->skipHeredoc($match[2]);
                    $clean .= 'null';
                    continue;
                }

                if ($char === '/') {
                    if ($this->peek('/')) {
                        $this->skipToNewline();
                        continue;
                    }
                    if ($this->peek('*')) {
                        $this->skipComment();
                    }
                }

                if ($this->maxMatches === 1 && isset(self::$typeConfig[$char])) {
                    $type = self::$typeConfig[$char];
                    if (
                        \substr($this->contents, $this->index, $type['length']) === $type['name']
                        && \preg_match($type['pattern'], $this->contents, $match, 0, $this->index - 1)
                    ) {
                        $clean .= $match[0];
                        return $clean;
                    }
                }

                $this->index += 1;
                if ($this->match(self::$restPattern, $match)) {
                    $clean .= $char . $match[0];
                    $this->index += \strlen($match[0]);
                } else {
                    $clean .= $char;
                }
            }
        }

        return $clean;
    }

    private function skipToPhp()
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '<' && $this->peek('?')) {
                $this->index += 2;
                break;
            }

            $this->index += 1;
        }
    }

    private function skipString($delimiter)
    {
        $this->index += 1;
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '\\' && ($this->peek('\\') || $this->peek($delimiter))) {
                $this->index += 2;
                continue;
            }
            if ($this->contents[$this->index] === $delimiter) {
                $this->index += 1;
                break;
            }
            $this->index += 1;
        }
    }

    private function skipComment()
    {
        $this->index += 2;
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '*' && $this->peek('/')) {
                $this->index += 2;
                break;
            }

            $this->index += 1;
        }
    }

    private function skipToNewline()
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === "\r" || $this->contents[$this->index] === "\n") {
                return;
            }
            $this->index += 1;
        }
    }

    private function skipHeredoc($delimiter)
    {
        $firstDelimiterChar = $delimiter[0];
        $delimiterLength = \strlen($delimiter);
        $delimiterPattern = '{'.preg_quote($delimiter).'(?![a-zA-Z0-9_\x80-\xff])}A';

        while ($this->index < $this->len) {
            // check if we find the delimiter after some spaces/tabs
            switch ($this->contents[$this->index]) {
                case "\t":
                case " ":
                    $this->index += 1;
                    continue 2;
                case $firstDelimiterChar:
                    if (
                        \substr($this->contents, $this->index, $delimiterLength) === $delimiter
                        && $this->match($delimiterPattern)
                    ) {
                        $this->index += $delimiterLength;
                        return;
                    }
                    break;
            }

            // skip the rest of the line
            while ($this->index < $this->len) {
                $this->skipToNewline();

                // skip newlines
                while ($this->index < $this->len && ($this->contents[$this->index] === "\r" || $this->contents[$this->index] === "\n")) {
                    $this->index += 1;
                }

                break;
            }
        }
    }

    private function peek($char)
    {
        return $this->index + 1 < $this->len && $this->contents[$this->index + 1] === $char;
    }

    private function match($regex, array &$match = null)
    {
        if (\preg_match($regex, $this->contents, $match, 0, $this->index)) {
            return true;
        }

        return false;
    }
}
