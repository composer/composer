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

namespace Composer;

use Symfony\Component\Console\Output\OutputInterface;

trigger_error('The ' . __NAMESPACE__ . '\XdebugHandler class is deprecated, use Composer\XdebugHandler\XdebugHandler instead,', E_USER_DEPRECATED);

/**
 * @deprecated use Composer\XdebugHandler\XdebugHandler instead
 */
class XdebugHandler extends XdebugHandler\XdebugHandler
{
    const ENV_ALLOW = 'COMPOSER_ALLOW_XDEBUG';
    const ENV_VERSION = 'COMPOSER_XDEBUG_VERSION';

    public function __construct(OutputInterface $output)
    {
        parent::__construct('composer', '--ansi');
    }
}
