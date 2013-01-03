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
 * Exception thrown when a package repository is utterly broken
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InvalidRepositoryException extends \Exception
{
}
