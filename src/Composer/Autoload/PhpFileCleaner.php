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

namespace Composer\Autoload;

use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @internal
 */
class PhpFileCleaner
{
    /** @var array<array{name: string, length: int, pattern: non-empty-string}> */
    private static $typeConfig;

    /** @var non-empty-string */
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

    /**
     * @param string[] $types
     * @return void
     */
    public static function setTypeConfig(array $types): void
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

    /**
     * @param string $contents
     * @param int $maxMatches
     */
    public function __construct(string $contents, int $maxMatches)
    {
        $this->contents = $contents;
        $this->len = \strlen($this->contents);
        $this->maxMatches = $maxMatches;
    }

    /**
     * @return string
     */
    public function clean(): string
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
                        continue;
                    }
                }

                if ($this->maxMatches === 1 && isset(self::$typeConfig[$char])) {
                    $type = self::$typeConfig[$char];
                    if (
                        \substr($this->contents, $this->index, $type['length']) === $type['name']
                        && Preg::isMatch($type['pattern'], $this->contents, $match, 0, $this->index - 1)
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

    /**
     * @return void
     */
    private function skipToPhp(): void
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === '<' && $this->peek('?')) {
                $this->index += 2;
                break;
            }

            $this->index += 1;
        }
    }

    /**
     * @param string $delimiter
     * @return void
     */
    private function skipString(string $delimiter): void
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

    /**
     * @return void
     */
    private function skipComment(): void
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

    /**
     * @return void
     */
    private function skipToNewline(): void
    {
        while ($this->index < $this->len) {
            if ($this->contents[$this->index] === "\r" || $this->contents[$this->index] === "\n") {
                return;
            }
            $this->index += 1;
        }
    }

    /**
     * @param string $delimiter
     * @return void
     */
    private function skipHeredoc(string $delimiter): void
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

    /**
     * @param string $char
     * @return bool
     */
    private function peek(string $char): bool
    {
        return $this->index + 1 < $this->len && $this->contents[$this->index + 1] === $char;
    }

    /**
     * @param non-empty-string $regex
     * @param null|array<int, string> $match
     * @return bool
     */
    private function match($regex, ?array &$match = null): bool
    {
        return Preg::isMatch($regex, $this->contents, $match, 0, $this->index);
    }
}
