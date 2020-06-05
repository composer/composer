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
 * Installed array repository.
 *
 * This is used as an in-memory InstalledRepository mostly for testing purposes
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstalledArrayRepository extends WritableArrayRepository implements InstalledRepositoryInterface
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
        // this is not a completely correct implementation but there is no way to
        // distinguish an empty repo and a newly created one given this is all in-memory
        return $this->count() === 0;
    }
}
