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


/**
 * @author Huy Nguyen <ngxhuy89@gmail.com>
 * support winrar archiver
 */
class RarArchiver implements ArchiverInterface
{
    protected static $formats = array(
        'zip',
        'rar',
        'rar5'
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, array $excludes = array())
    {
        $sources = realpath($sources);

        // Phar would otherwise load the file which we don't want
        if (file_exists($target)) {
            unlink($target);
        }
        // Try to use unrar on windows
        $rar = "C:\\Program Files\\WinRAR\\rar.exe";
        if(!file_exists($rar)){
          $rar = "C:\\Program Files(x86)\\WinRAR\\rar.exe";
        }
        if (file_exists($rar)) {
            $command = '"C:\\Program Files\\WinRAR\\rar.exe" a -r -ep1 -s -m5 ' . $target . " " . $sources . "\\*";
        }else{
            $command = 'rar a -r -ep1 -s -m5 ' . $target . " " . $sources . "\\*";
            //max compress
            //windows
        }
        if (0 === exec($command)) {
            return;
        }

    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return isset(static::$formats[$format]);
    }
}
