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

namespace Composer\FilterList;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Builds FilterListEntry objects from raw JSON dicts grouped by list name and drops entries
 * that do not match the supplied package constraint map.
 *
 * @internal
 * @final
 */
class FilterListEntryBuilder
{
    /** @var VersionParser */
    private $versionParser;

    public function __construct(?VersionParser $versionParser = null)
    {
        $this->versionParser = $versionParser ?? new VersionParser();
    }

    /**
     * @param array<mixed> $rawByList list name => raw entry dicts
     * @param array<string, ConstraintInterface> $packageConstraintMap
     * @param string|null $defaultPackage when the raw entries omit a "package" field (e.g. per-package
     *                                    metadata files where the package is implicit from the URL),
     *                                    use this as the package name.
     * @return array<string, list<FilterListEntry>>
     */
    public function build(array $rawByList, array $packageConstraintMap, ?string $defaultPackage = null): array
    {
        $result = [];
        foreach ($rawByList as $listName => $entries) {
            if (!is_string($listName) || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $data) {
                if (!is_array($data)) {
                    continue;
                }

                if (!isset($data['constraint'])) {
                    continue;
                }

                if (!isset($data['package'])) {
                    if ($defaultPackage === null) {
                        continue;
                    }

                    $data['package'] = $defaultPackage;
                }

                $entry = FilterListEntry::create($listName, $data, $this->versionParser);

                if (!isset($packageConstraintMap[$entry->packageName])) {
                    continue;
                }

                if (!$entry->constraint->matches($packageConstraintMap[$entry->packageName])) {
                    continue;
                }

                $result[$listName][] = $entry;
            }
        }

        return $result;
    }
}
