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

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;

use Symfony\Component\Finder;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiver implements ArchiverInterface
{
    static protected $formats = array(
        'zip' => \Phar::ZIP,
        'tar' => \Phar::TAR,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, $sourceRef = null, $excludes = array())
    {
        $sources = realpath($sources);

        $excludePatterns = $this->generatePatterns($excludes);

        try {
            $phar = new \PharData($target, null, null, static::$formats[$format]);
            $finder = new Finder\Finder();
            $finder
                ->in($sources)
                ->filter(function (\SplFileInfo $file) use ($sources, $excludePatterns) {
                    $relativePath = preg_replace('#^'.preg_quote($sources, '#').'#', '', $file->getRealPath());

                    $include = true;
                    foreach ($excludePatterns as $patternData) {
                        list($pattern, $negate) = $patternData;
                        if (preg_match($pattern, $relativePath)) {
                            $include = $negate;
                        }
                    }
                    return $include;
                })
                ->ignoreVCS(true);
            $phar->buildFromIterator($finder->getIterator(), $sources);
            return $target;
        } catch (\UnexpectedValueException $e) {
            $message = sprintf("Could not create archive '%s' from '%s': %s",
                $target,
                $sources,
                $e->getMessage()
            );

            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }

    /**
     * Generates a set of PCRE patterns from a set of exclude rules.
     *
     * @param array $rules A list of exclude rules similar to gitignore syntax
     */
    protected function generatePatterns($rules)
    {
        $patterns = array();
        foreach ($rules as $rule) {
            $negate = false;
            $pattern = '#';

            if (strlen($rule) && $rule[0] === '!') {
                $negate = true;
                $rule = substr($rule, 1);
            }

            if (strlen($rule) && $rule[0] === '/') {
                $pattern .= '^/';
                $rule = substr($rule, 1);
            }

            $pattern .= substr(Finder\Glob::toRegex($rule), 2, -2);
            $patterns[] = array($pattern . '#', $negate);
        }

        return $patterns;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return isset(static::$formats[$format]);
    }
}
