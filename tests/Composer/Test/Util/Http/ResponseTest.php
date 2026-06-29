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

namespace Composer\Test\Util\Http;

use Composer\Test\TestCase;
use Composer\Util\Http\Response;
use Seld\JsonLint\ParsingException;

class ResponseTest extends TestCase
{
    public function testDecodeJsonParsesValidBody()
    {
        $response = new Response(array('url' => 'https://example.org/packages.json'), 200, array(), '{"foo":"bar"}');

        $this->assertSame(array('foo' => 'bar'), $response->decodeJson());
    }

    public function testDecodeJsonDoesNotLeakResponseBodyOnParseError()
    {
        // The response body may contain sensitive information that must not be printed out. JsonLint
        // reports a window of the offending bytes - the tail of the consumed token - so the marker is
        // placed at the end of the string where it lands inside that window and would be echoed by the
        // unpatched decodeJson(). The fix must report only the URL.
        $url = 'http://169.254.169.254/latest/meta-data/iam/security-credentials';
        $body = '{"k":"secret-value-LEAKMARKER" X}';

        $response = new Response(array('url' => $url), 200, array(), $body);

        try {
            $response->decodeJson();
            $this->fail('Expected a ParsingException to be thrown for invalid JSON');
        } catch (ParsingException $e) {
            $this->assertStringContainsString($url, $e->getMessage());
            $this->assertStringNotContainsString('LEAKMARKER', $e->getMessage());
            $this->assertSame(array(), $e->getDetails());
        }
    }
}
