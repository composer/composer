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

namespace Composer\Compiler;

/**
 * The ComposerCompiler class compiles composer into a phar
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ComposerCompiler extends Compiler
{
    /**
     * {@inheritDoc}
     */
    protected function getProjectFiles()
    {
        return $this->getFinder()
            ->name('*')
            ->notName('ClassLoader.php')
            ->in($this->basePath . '/src')
            ->in($this->basePath . '/res');
    }

    /**
     * {@inheritDoc}
     */
    protected function getVendorFiles()
    {
        return $this->getFinder()
            ->name('*.php')
            ->exclude('Tests')
            ->in($this->basePath . '/vendor/symfony/')
            ->in($this->basePath . '/vendor/seld/jsonlint/src/')
            ->in($this->basePath . '/vendor/justinrainbow/json-schema/src/');
    }

    /**
     * {@inheritDoc}
     */
    protected function addBinary($phar)
    {
        $content = file_get_contents($this->basePath . '/bin/composer');
        $content = preg_replace('{^#!/usr/bin/env php\s*}', '', $content);
        $phar->addFromString('bin/composer', $content);
    }
}
