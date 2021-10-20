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
 * An exclude filter that processes gitignore and gitattributes
 *
 * It respects export-ignore git attributes
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class GitExcludeFilter extends BaseExcludeFilter
{
    /**
     * Parses .gitignore and .gitattributes files if they exist
     *
     * @param string $sourcePath
     */
    public function __construct($sourcePath)
    {
        parent::__construct($sourcePath);

        if (file_exists($sourcePath.'/.gitignore')) {
            $this->excludePatterns = $this->parseLines(
                file($sourcePath.'/.gitignore'),
                array($this, 'parseGitIgnoreLine')
            );
        }
        if (file_exists($sourcePath.'/.gitattributes')) {
            $this->excludePatterns = array_merge(
                $this->excludePatterns,
                $this->parseLines(
                    file($sourcePath.'/.gitattributes'),
                    array($this, 'parseGitAttributesLine')
                )
            );
        }
    }

    /**
     * Callback line parser which process gitignore lines
     *
     * @param string $line A line from .gitignore
     *
     * @return array{0: string, 1: bool, 2: bool} An exclude pattern for filter()
     */
    public function parseGitIgnoreLine($line)
    {
        return $this->generatePattern($line);
    }

    /**
     * Callback parser which finds export-ignore rules in git attribute lines
     *
     * @param string $line A line from .gitattributes
     *
     * @return array{0: string, 1: bool, 2: bool}|null An exclude pattern for filter()
     */
    public function parseGitAttributesLine($line)
    {
        $parts = preg_split('#\s+#', $line);

        if (count($parts) == 2 && $parts[1] === 'export-ignore') {
            return $this->generatePattern($parts[0]);
        }

        return null;
    }
}
