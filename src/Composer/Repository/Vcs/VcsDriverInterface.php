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

namespace Composer\Repository\Vcs;

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @internal
 */
interface VcsDriverInterface
{
    /**
     * Initializes the driver (git clone, svn checkout, fetch info etc)
     *
     * @return void
     */
    public function initialize(): void;

    /**
     * Return the composer.json file information
     *
     * @param  string  $identifier Any identifier to a specific branch/tag/commit
     * @return mixed[]|null Array containing all infos from the composer.json file, or null to denote that no file was present
     */
    public function getComposerInformation(string $identifier): ?array;

    /**
     * Return the content of $file or null if the file does not exist.
     *
     * @param  string      $file
     * @param  string      $identifier
     * @return string|null
     */
    public function getFileContent(string $file, string $identifier): ?string;

    /**
     * Get the changedate for $identifier.
     *
     * @param  string         $identifier
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable;

    /**
     * Return the root identifier (trunk, master, default/tip ..)
     *
     * @return string Identifier
     */
    public function getRootIdentifier(): string;

    /**
     * Return list of branches in the repository
     *
     * @return array<int|string, string> Branch names as keys, identifiers as values
     */
    public function getBranches(): array;

    /**
     * Return list of tags in the repository
     *
     * @return array<int|string, string> Tag names as keys, identifiers as values
     */
    public function getTags(): array;

    /**
     * @param string $identifier Any identifier to a specific branch/tag/commit
     *
     * @return array{type: string, url: string, reference: string, shasum: string}|null
     */
    public function getDist(string $identifier): ?array;

    /**
     * @param string $identifier Any identifier to a specific branch/tag/commit
     *
     * @return array{type: string, url: string, reference: string}
     */
    public function getSource(string $identifier): array;

    /**
     * Return the URL of the repository
     *
     * @return string
     */
    public function getUrl(): string;

    /**
     * Return true if the repository has a composer file for a given identifier,
     * false otherwise.
     *
     * @param  string $identifier Any identifier to a specific branch/tag/commit
     * @return bool   Whether the repository has a composer file for a given identifier.
     */
    public function hasComposerFile(string $identifier): bool;

    /**
     * Performs any cleanup necessary as the driver is not longer needed
     *
     * @return void
     */
    public function cleanup(): void;

    /**
     * Checks if this driver can handle a given url
     *
     * @param  IOInterface $io     IO instance
     * @param  Config      $config current $config
     * @param  string      $url    URL to validate/check
     * @param  bool        $deep   unless true, only shallow checks (url matching typically) should be done
     * @return bool
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool;
}
