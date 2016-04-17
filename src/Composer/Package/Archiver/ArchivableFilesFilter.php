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

namespace Composer\Package\Archiver;

use FilterIterator;
use PharData;

class ArchivableFilesFilter extends FilterIterator
{
    private $dirs = array();

    /**
     * @return bool true if the current element is acceptable, otherwise false.
     */
    public function accept()
    {
        $file = $this->getInnerIterator()->current();
        if ($file->isDir()) {
            $this->dirs[] = (string)$file;

            return false;
        }

        return true;
    }

    public function addEmptyDir(PharData $phar, $sources)
    {
        foreach ($this->dirs as $filepath) {
            $localname = str_replace($sources . "/", '', $filepath);
            $phar->addEmptyDir($localname);
        }
    }
}
