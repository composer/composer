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

namespace Composer\Test\Mock;

use Composer\Repository\InstalledFilesystemRepository;

class InstalledFilesystemRepositoryMock extends InstalledFilesystemRepository
{
    public function reload()
    {
    }

    public function write()
    {
    }
}
