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
 * Installed filesystem repository.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstalledFilesystemRepository extends FilesystemRepository implements InstalledRepositoryInterface
{
    public function getRepoName()
    {
        return 'installed '.parent::getRepoName();
    }

    /**
     * {@inheritDoc}
     */
    public function isFresh()
    {
        return !$this->file->exists();
    }
}
