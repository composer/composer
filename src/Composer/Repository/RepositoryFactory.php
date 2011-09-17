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

namespace Composer\Repository;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class RepositoryFactory
{
    private $classes = array();

    public function __construct(array $classes)
    {
        foreach ($classes as $class) {
            $this->registerRepositoryClass($class);
        }
    }

    public function registerRepositoryClass($class)
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->implementsInterface('Composer\Repository\RepositoryInterface')) {
            throw new \InvalidArgumentException(
                'Repository class should implement "RepositoryInterface", but "'.$class.'"'.
                'given'
            );
        }

        $this->classes[] = $class;
    }

    public function classWhichSupports($type, $name = '', $url = '')
    {
        foreach ($this->classes as $class) {
            if ($class::supports($type, $name, $url)) {
                return $class;
            }
        }

        throw new \UnexpectedValueException(sprintf(
            "Can not find repository class, which supports:\n%s",
            json_encode(array($name => array($type => $url)))
        ));
    }

    public function create($type, $name = '', $url = '')
    {
        $class = $this->classWhichSupports($type, $name, $url);

        return $class::create($type, $name, $url);
    }
}
