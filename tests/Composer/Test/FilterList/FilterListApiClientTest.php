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

namespace Composer\Test\FilterList;

use Composer\FilterList\FilterListApiClient;
use Composer\Json\JsonFile;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class FilterListApiClientTest extends TestCase
{
    public function testPostPurlsSendsPackagesAndListsAsBody(): void
    {
        $expectedApiRequestBody = json_encode([
            'packages' => ['pkg://composer/vendor/foo', 'pkg://composer/vendor/bar'],
            'lists' => ['malware', 'typosquatting'],
        ]);
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            [
                [
                    'url' => 'https://example.org/api/filter',
                    'options' => [
                        'http' => [
                            'method' => 'POST',
                            'header' => ['Content-type: application/json'],
                            'timeout' => 10,
                            'content' => $expectedApiRequestBody,
                        ],
                    ],
                    'body' => JsonFile::encode(['filter' => []]),
                ],
            ],
            true
        );

        $client = new FilterListApiClient($httpDownloader);
        $response = $client->postPurls(
            'https://example.org/api/filter',
            [
                'vendor/foo' => new Constraint('=', '1.0.0.0'),
                'vendor/bar' => new Constraint('=', '2.0.0.0'),
            ],
            ['malware', 'typosquatting']
        );

        self::assertSame(['filter' => []], $response->decodeJson());
    }
}
