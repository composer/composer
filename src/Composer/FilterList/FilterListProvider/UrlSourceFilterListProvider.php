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

use Composer\FilterList\FilterListApiClient;
use Composer\FilterList\FilterListEntryBuilder;
use Composer\FilterList\Source\UrlSource;
use Composer\Repository\FilterListProviderInterface;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @final
 * @readonly
 */
class UrlSourceFilterListProvider implements FilterListProviderInterface
{
    /** @var FilterListApiClient */
    private $apiClient;
    /** @var FilterListEntryBuilder */
    private $entryBuilder;
    /** @var UrlSource */
    private $source;

    public function __construct(
        HttpDownloader $httpDownloader,
        UrlSource $source
    ) {
        $this->apiClient = new FilterListApiClient($httpDownloader);
        $this->entryBuilder = new FilterListEntryBuilder();
        $this->source = $source;
    }

    public function hasFilter(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getFilter(array $packageConstraintMap, array $configuredLists): array
    {
        $response = $this->apiClient->postPurls($this->source->url, $packageConstraintMap, [$this->source->listName]);

        $decoded = $response->decodeJson();
        $entries = isset($decoded['filter']) && is_array($decoded['filter']) ? $decoded['filter'] : [];

        // The remote returns a flat list of entries; this provider is bound to a single list name.
        $rawByList = [$this->source->listName => $entries];

        return ['filter' => $this->entryBuilder->build($rawByList, $packageConstraintMap)];
    }

    public function getFilterLists(): array
    {
        return [$this->source->listName];
    }
}
