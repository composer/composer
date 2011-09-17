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
 * @author Nils Adermann <naderman@naderman.de>
 */
interface RepositoryInterface extends \Countable
{
    static function supports($type, $name = '', $url = '');
    static function create($type, $name = '', $url = '');

    function getPackages();
}
