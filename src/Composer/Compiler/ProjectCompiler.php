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
 * The ProjectCompiler class compiles a project into a phar
 *
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ProjectCompiler extends Compiler
{
    /**
     * {@inheritDoc}
     */
    protected function getProjectFiles()
    {
        return $this->getFinder()
            ->name('*')
            ->exclude(array('Tests', 'tests', 'vendor'))
            ->in($this->basePath);
    }

    /**
     * {@inheritDoc}
     */
    protected function getVendorFiles()
    {
        return $this->getFinder()
            ->name('*')
            ->exclude(array('Tests', 'tests'))
            ->in($this->basePath . '/vendor');
    }

    /**
     * {@inheritDoc}
     */
    protected function addBinary($phar)
    {
    }
}
