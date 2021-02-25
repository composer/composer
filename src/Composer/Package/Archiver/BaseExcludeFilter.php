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
 * @author Nils Adermann <naderman@naderman.de>
 */
abstract class BaseExcludeFilter
{
    /**
     * @var string
     */
    protected $sourcePath;

    /**
     * @var array
     */
    protected $excludePatterns;

    /**
     * @param string $sourcePath Directory containing sources to be filtered
     */
    public function __construct($sourcePath)
    {
        $this->sourcePath = $sourcePath;
        $this->excludePatterns = array();
    }

    /**
     * Checks the given path against all exclude patterns in this filter
     *
     * Negated patterns overwrite exclude decisions of previous filters.
     *
     * @param string $relativePath The file's path relative to the sourcePath
     * @param bool   $exclude      Whether a previous filter wants to exclude this file
     *
     * @return bool Whether the file should be excluded
     */
    public function filter($relativePath, $exclude)
    {
        foreach ($this->excludePatterns as $patternData) {
            list($pattern, $negate, $stripLeadingSlash) = $patternData;

            if ($stripLeadingSlash) {
                $path = substr($relativePath, 1);
            } else {
                $path = $relativePath;
            }

            if (@preg_match($pattern, $path)) {
                $exclude = !$negate;
            }
        }

        return $exclude;
    }

    /**
     * Processes a file containing exclude rules of different formats per line
     *
     * @param array    $lines      A set of lines to be parsed
     * @param callable $lineParser The parser to be used on each line
     *
     * @return array Exclude patterns to be used in filter()
     */
    protected function parseLines(array $lines, $lineParser)
    {
        return array_filter(
            array_map(
                function ($line) use ($lineParser) {
                    $line = trim($line);

                    if (!$line || 0 === strpos($line, '#')) {
                        return null;
                    }

                    return call_user_func($lineParser, $line);
                },
                $lines
            ),
            function ($pattern) {
                return $pattern !== null;
            }
        );
    }

    /**
     * Generates a set of exclude patterns for filter() from gitignore rules
     *
     * @param array $rules A list of exclude rules in gitignore syntax
     *
     * @return array Exclude patterns
     */
    protected function generatePatterns($rules)
    {
        $patterns = array();
        foreach ($rules as $rule) {
            $patterns[] = $this->generatePattern($rule);
        }

        return $patterns;
    }

    /**
     * Generates an exclude pattern for filter() from a gitignore rule
     *
     * @param string $rule An exclude rule in gitignore syntax
     *
     * @return array An exclude pattern
     */
    protected function generatePattern($rule)
    {
        $negate = false;
        $pattern = '';

        if ($rule !== '' && $rule[0] === '!') {
            $negate = true;
            $rule = ltrim($rule, '!');
        }

        $firstSlashPosition = strpos($rule, '/');
        if (0 === $firstSlashPosition) {
            $pattern = '^/';
        } elseif (false === $firstSlashPosition || strlen($rule) - 1 === $firstSlashPosition) {
            $pattern = '/';
        }

        $rule = trim($rule, '/');

        // remove delimiters as well as caret (^) and dollar sign ($) from the regex
        $rule = substr(Finder\Glob::toRegex($rule), 2, -2);

        return array('{'.$pattern.$rule.'(?=$|/)}', $negate, false);
    }
}
