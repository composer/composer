<?php declare(strict_types=1);

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

use ZipArchive;
use Composer\Util\Filesystem;

/**
 * @author Jan Prieser <jan@prieser.net>
 */
class ZipArchiver implements ArchiverInterface
{
    /** @var array<string, bool> */
    protected static $formats = [
        'zip' => true,
    ];

    /**
     * @inheritDoc
     */
    public function archive(string $sources, string $target, string $format, array $excludes = [], bool $ignoreFilters = false): string
    {
        $fs = new Filesystem();
        $sources = $fs->normalizePath($sources);

        $zip = new ZipArchive();
        $res = $zip->open($target, ZipArchive::CREATE);
        if ($res === true) {
            $files = new ArchivableFilesFinder($sources, $excludes, $ignoreFilters);
            foreach ($files as $file) {
                /** @var \Symfony\Component\Finder\SplFileInfo $file */
                $filepath = strtr($file->getPath()."/".$file->getFilename(), '\\', '/');
                $localname = $filepath;
                if (strpos($localname, $sources . '/') === 0) {
                    $localname = substr($localname, strlen($sources . '/'));
                }
                if ($file->isDir()) {
                    $zip->addEmptyDir($localname);
                } else {
                    $zip->addFile($filepath, $localname);
                }

                /**
                 * setExternalAttributesName() is only available with libzip 0.11.2 or above
                 */
                if (method_exists($zip, 'setExternalAttributesName')) {
                    $perms = fileperms($filepath);

                    /**
                     * Ensure to preserve the permission umasks for the filepath in the archive.
                     */
                    $zip->setExternalAttributesName($localname, ZipArchive::OPSYS_UNIX, $perms << 16);
                }
            }
            if ($zip->close()) {
                return $target;
            }
        }
        $message = sprintf(
            "Could not create archive '%s' from '%s': %s",
            $target,
            $sources,
            $zip->getStatusString()
        );
        throw new \RuntimeException($message);
    }

    /**
     * @inheritDoc
     */
    public function supports(string $format, ?string $sourceType): bool
    {
        return isset(static::$formats[$format]) && $this->compressionAvailable();
    }

    private function compressionAvailable(): bool
    {
        return class_exists('ZipArchive');
    }
}
