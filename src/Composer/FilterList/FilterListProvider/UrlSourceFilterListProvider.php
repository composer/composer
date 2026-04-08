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

namespace Composer\FilterList\FilterListProvider;

use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FilterListProviderConfig;
use Composer\FilterList\Source\UrlSource;
use Composer\Package\Version\VersionParser;
use Composer\Repository\FilterListProviderInterface;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @final
 * @readonly
 */
class UrlSourceFilterListProvider implements FilterListProviderInterface
{
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var UrlSource */
    private $source;

    public function __construct(
        HttpDownloader $httpDownloader,
        UrlSource $source
    ) {
        $this->httpDownloader = $httpDownloader;
        $this->source = $source;
    }

    public function hasFilter(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getFilter(array $packageConstraintMap): array
    {
        $purls = array_map(static function (string $packageName) {
            return 'pkg://composer/' . $packageName;
        }, array_keys($packageConstraintMap));

        $options = [];
        $options['http']['method'] = 'POST';
        $options['http']['header'][] = 'Content-type: application/json';
        $options['http']['timeout'] = 10;
        $options['http']['content'] = json_encode(['packages' => $purls]);

        $response = $this->httpDownloader->get($this->source->url, $options);

        $map = [];
        $parser = new VersionParser();
        foreach ($response->decodeJson()['filter'] as $data) {
            $entry = FilterListEntry::create($this->source->name, $data, $parser);
            if (!isset($packageConstraintMap[$entry->packageName])) {
                continue;
            }

            if (!$entry->constraint->matches($packageConstraintMap[$entry->packageName])) {
                continue;
            }

            $map[$this->source->name][] = $entry;
        }

        return ['filter' => $map, 'config' => FilterListProviderConfig::fromConfig(true, [$this->source->name])];
    }
}
