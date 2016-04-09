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

namespace Composer\Autoload;

/**
 * By-inheritance ClassLoader proxy caching results in APCu.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ApcuClassLoader extends ClassLoader
{
    private $prefix;
    private $initializer;

    public function __construct($prefix, \Closure $initializer)
    {
        $this->prefix = $prefix;
        $this->initializer = $initializer;
    }

    public function getPrefixes()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getPrefixes();
    }

    public function getPrefixesPsr4()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getPrefixesPsr4();
    }

    public function getFallbackDirs()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getFallbackDirs();
    }

    public function getFallbackDirsPsr4()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getFallbackDirsPsr4();
    }

    public function getClassMap()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getClassMap();
    }

    /**
     * {@inheritdoc}
     */
    public function addClassMap(array $classMap)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::addClassMap($classMap);
    }

    /**
     * {@inheritdoc}
     */
    public function add($prefix, $paths, $prepend = false)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::add($prefix, $paths, $prepend);
    }

    /**
     * {@inheritdoc}
     */
    public function addPsr4($prefix, $paths, $prepend = false)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::addPsr4($prefix, $paths, $prepend);
    }

    /**
     * {@inheritdoc}
     */
    public function set($prefix, $paths)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::set($prefix, $paths);
    }

    /**
     * {@inheritdoc}
     */
    public function setPsr4($prefix, $paths)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::setPsr4($prefix, $paths);
    }

    /**
     * {@inheritdoc}
     */
    public function setUseIncludePath($useIncludePath)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::setUseIncludePath($useIncludePath);
    }

    /**
     * {@inheritdoc}
     */
    public function getUseIncludePath()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::getUseIncludePath();
    }

    /**
     * {@inheritdoc}
     */
    public function setClassMapAuthoritative($classMapAuthoritative)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        parent::setClassMapAuthoritative($classMapAuthoritative);
    }

    /**
     * {@inheritdoc}
     */
    public function isClassMapAuthoritative()
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        return parent::isClassMapAuthoritative();
    }

    /**
     * {@inheritdoc}
     */
    public function loadClass($class)
    {
        $file = apcu_fetch($this->prefix.$class, $success);

        if ($file || (!$success && $file = $this->findFile($class))) {
            includeFile($file);

            return true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findFile($class)
    {
        if (null !== $this->initializer) {
            $this->initialize();
        }

        apcu_store($this->prefix.$class, $file = parent::findFile($class));

        return $file;
    }

    private function initialize()
    {
        $initializer = $this->initializer;
        $this->initializer = null;
        $initializer($this);
    }
}
