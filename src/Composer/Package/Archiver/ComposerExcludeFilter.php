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

/**
 * An exclude filter which processes composer's own exclude rules
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ComposerExcludeFilter extends BaseExcludeFilter
{
    /**
     * @param string       $sourcePath   Directory containing sources to be filtered
     * @param array|string $excludeRules An array of exclude rules from composer.json
     */
    public function __construct($sourcePath, $excludeRules = array())
    {
        parent::__construct($sourcePath);

        if ($excludeRules) {
            $this->parseExcludes($excludeRules);
        }
    }

    /**
     * parses the exclude rules from the composer.json itself (either array or file path)
     *
     * @param $excludeRules
     *
     * @throws \InvalidArgumentException if the archive.exclude contains something unparsable
     */
    private function parseExcludes($excludeRules)
    {
        if (is_string($excludeRules) && is_readable($excludeRules) && is_file($excludeRules)) {
            $this->excludePatterns = $this->parseLines(
                file($excludeRules),
                array($this, 'parseIgnoreFileLine')
            );
        } elseif (is_array($excludeRules)) {
            $this->excludePatterns = $this->generatePatterns($excludeRules);
        } else {
            throw new \InvalidArgumentException('"archive.exclude" is invalid, either provide an array of excludes or a readable file path ');
        }
    }

    /**
     * Callback line parser which process ignore file lines
     *
     * @param string $line
     * @return array
     */
    public function parseIgnoreFileLine($line)
    {
        return $this->generatePattern($line);
    }
}
