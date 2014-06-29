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

namespace Composer\Command\Helper;

use Composer\Util\Filesystem;
use Symfony\Component\Console\Helper\Helper;

class FilesystemHelper extends Helper
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function ensureFileExists($file, $content = '')
    {
        return $this->filesystem->ensureFileExists($file, $content);
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'filesystem';
    }
}
