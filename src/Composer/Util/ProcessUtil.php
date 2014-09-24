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

namespace Composer\Util;

use Symfony\Component\Process\ProcessUtils;

/**
 * @author Frederik Bosch <f.bosch@genkgo.nl>
 */

class ProcessUtil
{
    
	/**
	 * Escapes a string to be used as a shell argument.
	 *
	 * @param string $argument The argument that will be escaped
	 *
	 * @return string The escaped argument
	 */
	public static function escapeArgument ($argument)
	{
		return ProcessUtils::escapeArgument($argument);
	}
	
	
}
