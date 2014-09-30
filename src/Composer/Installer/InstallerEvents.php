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

namespace Composer\Installer;

/**
 * The Installer Events.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
class InstallerEvents
{
    /**
     * The PRE_SOLVE_DEPENDENCIES event occurs as a installer begins
     * resolve operations.
     *
     * The event listener method receives a
     * Composer\Installer\InstallerEvent instance.
     *
     * @var string
     */
    const PRE_SOLVE_DEPENDENCIES = 'pre-solve-dependencies';

    /**
     * The POST_SOLVE_DEPENDENCIES event occurs as a installer after
     * resolve operations.
     *
     * The event listener method receives a
     * Composer\Installer\InstallerEvent instance.
     *
     * @var string
     */
    const POST_SOLVE_DEPENDENCIES = 'post-solve-dependencies';
}
