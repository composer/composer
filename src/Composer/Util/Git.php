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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Git
{
    public function cleanEnv()
    {
        if (ini_get('safe_mode') && false === strpos(ini_get('safe_mode_allowed_env_vars'), 'GIT_ASKPASS')) {
            throw new \RuntimeException('safe_mode is enabled and safe_mode_allowed_env_vars does not contain GIT_ASKPASS, can not set env var. You can disable safe_mode with "-dsafe_mode=0" when running composer');
        }

        // added in git 1.7.1, prevents prompting the user for username/password
        if (getenv('GIT_ASKPASS') !== 'echo') {
            putenv('GIT_ASKPASS=echo');
        }

        // clean up rogue git env vars in case this is running in a git hook
        if (getenv('GIT_DIR')) {
            putenv('GIT_DIR');
        }
        if (getenv('GIT_WORK_TREE')) {
            putenv('GIT_WORK_TREE');
        }
    }
}
