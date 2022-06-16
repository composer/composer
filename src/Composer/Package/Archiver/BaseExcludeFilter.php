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

namespace Composer\Package\Archiver;

use Composer\Pcre\Preg;
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
     * @var array<array{0: non-empty-string, 1: bool, 2: bool}> array of [$pattern, $negate, $stripLeadingSlash] arrays
     */
    protected $excludePatterns;

    /**
     * @param string $sourcePath Directory containing sources to be filtered
     */
    public function __construct(string $sourcePath)
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
     * @param bool $exclude Whether a previous filter wants to exclude this file
     *
     * @return bool Whether the file should be excluded
     */
    public function filter(string $relativePath, bool $exclude): bool
    {
        foreach ($this->excludePatterns as $patternData) {
            list($pattern, $negate, $stripLeadingSlash) = $patternData;

            if ($stripLeadingSlash) {
                $path = substr($relativePath, 1);
            } else {
                $path = $relativePath;
            }

            try {
                if (Preg::isMatch($pattern, $path)) {
                    $exclude = !$negate;
                }
            } catch (\RuntimeException $e) {
                // suppressed
            }
        }

        return $exclude;
    }

    /**
     * Processes a file containing exclude rules of different formats per line
     *
     * @param string[] $lines A set of lines to be parsed
     * @param callable $lineParser The parser to be used on each line
     *
     * @return array<array{0: non-empty-string, 1: bool, 2: bool}> Exclude patterns to be used in filter()
     */
    protected function parseLines(array $lines, callable $lineParser): array
    {
        return array_filter(
            array_map(
                function ($line) use ($lineParser) {
                    $line = trim($line);

                    if (!$line ||   str_starts_with($line, '#')) {
                        return null;
                    }

                    return call_user_func($lineParser, $line);
                },
                $lines
            ),
            function ($pattern): bool {
                return $pattern !== null;
            }
        );
    }

    /**
     * Generates a set of exclude patterns for filter() from gitignore rules
     *
     * @param string[] $rules A list of exclude rules in gitignore syntax
     *
     * @return array<int, array{0: non-empty-string, 1: bool, 2: bool}> Exclude patterns
     */
    protected function generatePatterns(array $rules): array
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
     * @return array{0: non-empty-string, 1: bool, 2: bool} An exclude pattern
     */
    protected function generatePattern(string $rule): array
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
