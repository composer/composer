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

use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use FilesystemIterator;
use FilterIterator;
use Iterator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A Symfony Finder wrapper which locates files that should go into archives
 *
 * Handles .gitignore, .gitattributes and .hgignore files as well as composer's
 * own exclude rules from composer.json
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @phpstan-extends FilterIterator<string, SplFileInfo, Iterator<string, SplFileInfo>>
 */
class ArchivableFilesFinder extends FilterIterator
{
    /**
     * @var Finder
     */
    protected $finder;

    /**
     * Initializes the internal Symfony Finder with appropriate filters
     *
     * @param string $sources Path to source files to be archived
     * @param string[] $excludes Composer's own exclude rules from composer.json
     * @param bool $ignoreFilters Ignore filters when looking for files
     */
    public function __construct(string $sources, array $excludes, bool $ignoreFilters = false)
    {
        $fs = new Filesystem();

        $sourcesRealPath = realpath($sources);
        if ($sourcesRealPath === false) {
            throw new \RuntimeException('Could not realpath() the source directory "'.$sources.'"');
        }
        $sources = $fs->normalizePath($sourcesRealPath);

        if ($ignoreFilters) {
            $filters = [];
        } else {
            $filters = [
                new GitExcludeFilter($sources),
                new ComposerExcludeFilter($sources, $excludes),
            ];
        }

        $this->finder = new Finder();

        $filter = static function (\SplFileInfo $file) use ($sources, $filters, $fs): bool {
            $realpath = $file->getRealPath();
            if ($realpath === false) {
                return false;
            }
            if ($file->isLink() && strpos($realpath, $sources) !== 0) {
                return false;
            }

            $relativePath = Preg::replace(
                '#^'.preg_quote($sources, '#').'#',
                '',
                $fs->normalizePath($realpath)
            );

            $exclude = false;
            foreach ($filters as $filter) {
                $exclude = $filter->filter($relativePath, $exclude);
            }

            return !$exclude;
        };

        $this->finder
            ->in($sources)
            ->filter($filter)
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->sortByName();

        parent::__construct($this->finder->getIterator());
    }

    public function accept(): bool
    {
        /** @var SplFileInfo $current */
        $current = $this->getInnerIterator()->current();

        if (!$current->isDir()) {
            return true;
        }

        $iterator = new FilesystemIterator((string) $current, FilesystemIterator::SKIP_DOTS);

        return !$iterator->valid();
    }
}
