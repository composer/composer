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

namespace Composer\Repository\Vcs;

/**
 * @author Matthew Davis <mdavi1982@gmail.com>
 */
class GitMonoDriver extends GitDriver
{

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function normalizeTag( $tag )
    {
        return basename($tag);
    }
}
