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

    public function testPostPurlsSendsRepositoryTransportOptions(): void
    {
        $expectedApiRequestBody = json_encode([
            'packages' => ['pkg://composer/vendor/foo'],
            'lists' => ['malware'],
        ]);

        $variationsThatShouldWork = [
            (object) ['X-Cops: S07E12'],
            'X-Cops: S07E12',
            ['X-Cops: S07E12'],
            json_decode('{"header": "X-Cops: S07E12"}')->header,
            json_decode('{"header": {"0": "X-Cops: S07E12"}}')->header,
            json_decode('["X-Cops: S07E12"]'),
        ];
        $httpDownloader = $this->getHttpDownloaderMock();
        $httpDownloader->expects(
            array_fill(0, count($variationsThatShouldWork), [
                'url' => 'https://example.org/api/filter',
                'options' => [
                    'ssl' => ['verify_peer' => false],
                    'http' => [
                        'header' => ['X-Cops: S07E12', 'Content-type: application/json'],
                        'method' => 'POST',
                        'timeout' => 10,
                        'content' => $expectedApiRequestBody,
                    ],
                ],
                'body' => JsonFile::encode(['filter' => []]),
            ]),
            true
        );

        foreach ($variationsThatShouldWork as $variation) {
            $client = new FilterListApiClient($httpDownloader, [
                'ssl' => ['verify_peer' => false],
                'http' => ['header' => $variation],
            ]);
            $response = $client->postPurls(
                'https://example.org/api/filter',
                ['vendor/foo' => new Constraint('=', '1.0.0.0')],
                ['malware']
            );

            self::assertSame(['filter' => []], $response->decodeJson());
        }

    }
}
