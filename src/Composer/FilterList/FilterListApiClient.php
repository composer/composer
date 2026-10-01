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

use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @final
 * @readonly
 */
class FilterListApiClient
{
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var mixed[] */
    private $options;

    /**
     * @param mixed[] $options Stream context options e.g. https://www.php.net/manual/en/context.http.php
     */
    public function __construct(HttpDownloader $httpDownloader, array $options = [])
    {
        $this->httpDownloader = $httpDownloader;
        $this->options = $options;
    }

    /**
     * POST a list of PURLs (and optionally configured list names) to a remote filter list endpoint.
     *
     * @param array<string, \Composer\Semver\Constraint\ConstraintInterface> $packageConstraintMap
     * @param list<string> $configuredLists
     */
    public function postPurls(string $url, array $packageConstraintMap, array $configuredLists): Response
    {
        $purls = array_map(static function (string $packageName): string {
            return 'pkg://composer/' . $packageName;
        }, array_keys($packageConstraintMap));

        $body = [
            'packages' => $purls,
            'lists' => $configuredLists,
        ];

        $options = $this->options;
        $options['http']['method'] = 'POST';
        if (isset($options['http']['header'])) {
            $options['http']['header'] = (array) $options['http']['header'];
        }
        $options['http']['header'][] = 'Content-type: application/json';
        $options['http']['timeout'] = 10;
        $options['http']['content'] = json_encode($body);

        return $this->httpDownloader->get($url, $options);
    }
}
