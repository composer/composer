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

namespace Composer\Exception;

/**
 * Unknown package exception.
 *
 * Used when a package isn't found in a list of valid packages.
 *
 * @author Chris Wilkinson <chriswilkinson84@gmail.com>
 */
class UnknownPackageException extends \UnexpectedValueException
{
}