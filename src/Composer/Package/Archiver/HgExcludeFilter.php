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

namespace Composer\Package\Archiver;

use Symfony\Component\Finder;

/**
 * An exclude filter that processes hgignore files
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class HgExcludeFilter extends BaseExcludeFilter
{
    const HG_IGNORE_REGEX = 1;
    const HG_IGNORE_GLOB = 2;

    /**
     * Either HG_IGNORE_REGEX or HG_IGNORE_GLOB
     * @var int
     */
    protected $patternMode;

    /**
     * Parses .hgignore file if it exist
     *
     * @param string $sourcePath
     */
    public function __construct($sourcePath)
    {
        parent::__construct($sourcePath);

        $this->patternMode = self::HG_IGNORE_REGEX;

        if (file_exists($sourcePath.'/.hgignore')) {
            $this->excludePatterns = $this->parseLines(
                file($sourcePath.'/.hgignore'),
                array($this, 'parseHgIgnoreLine')
            );
        }
    }

    /**
     * Callback line parser which process hgignore lines
     *
     * @param string $line A line from .hgignore
     *
     * @return array|null An exclude pattern for filter()
     */
    public function parseHgIgnoreLine($line)
    {
        if (preg_match('#^syntax\s*:\s*(glob|regexp)$#', $line, $matches)) {
            if ($matches[1] === 'glob') {
                $this->patternMode = self::HG_IGNORE_GLOB;
            } else {
                $this->patternMode = self::HG_IGNORE_REGEX;
            }

            return null;
        }

        if ($this->patternMode == self::HG_IGNORE_GLOB) {
            return $this->patternFromGlob($line);
        }

        return $this->patternFromRegex($line);
    }

    /**
     * Generates an exclude pattern for filter() from a hg glob expression
     *
     * @param string $line A line from .hgignore in glob mode
     *
     * @return array An exclude pattern for filter()
     */
    protected function patternFromGlob($line)
    {
        $pattern = '#'.substr(Finder\Glob::toRegex($line), 2, -1).'#';
        $pattern = str_replace('[^/]*', '.*', $pattern);

        return array($pattern, false, true);
    }

    /**
     * Generates an exclude pattern for filter() from a hg regexp expression
     *
     * @param string $line A line from .hgignore in regexp mode
     *
     * @return array An exclude pattern for filter()
     */
    public function patternFromRegex($line)
    {
        // WTF need to escape the delimiter safely
        $pattern = '#'.preg_replace('/((?:\\\\\\\\)*)(\\\\?)#/', '\1\2\2\\#', $line).'#';

        return array($pattern, false, true);
    }
}
