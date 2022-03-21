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

/**
 * An exclude filter that processes gitattributes
 *
 * It respects export-ignore git attributes
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class GitExcludeFilter extends BaseExcludeFilter
{
    /**
     * Parses .gitattributes if it exists
     *
     * @param string $sourcePath
     */
    public function __construct(string $sourcePath)
    {
        parent::__construct($sourcePath);

        if (file_exists($sourcePath.'/.gitattributes')) {
            $this->excludePatterns = array_merge(
                $this->excludePatterns,
                $this->parseLines(
                    file($sourcePath.'/.gitattributes'),
                    [$this, 'parseGitAttributesLine']
                )
            );
        }
    }

    /**
     * Callback parser which finds export-ignore rules in git attribute lines
     *
     * @param string $line A line from .gitattributes
     *
     * @return array{0: string, 1: bool, 2: bool}|null An exclude pattern for filter()
     */
    public function parseGitAttributesLine(string $line): ?array
    {
        $parts = Preg::split('#\s+#', $line);

        if (count($parts) == 2 && $parts[1] === 'export-ignore') {
            return $this->generatePattern($parts[0]);
        }

        if (count($parts) == 2 && $parts[1] === '-export-ignore') {
            return $this->generatePattern('!'.$parts[0]);
        }

        return null;
    }
}
