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

namespace Composer\EventDispatcher;

/**
 * Folder Event Interface
 *
 * For events that centers around a folder, like Package events, this is passed on to process executors.
 */
interface FolderEventInterface
{
    /**
     * Returns the event's current working directory.
     *
     * @return string The current working directory
     */
    public function getCurrentWorkingDirectory();
}
