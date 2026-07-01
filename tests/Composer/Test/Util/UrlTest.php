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

namespace Composer\Test\Util;

use Composer\Util\Url;
use Composer\Test\TestCase;
use Composer\Config;

class UrlTest extends TestCase
{
    /**
     * @dataProvider distRefsProvider
     *
     * @param array<string, mixed> $conf
     * @param non-empty-string $url
     */
    public function testUpdateDistReference(string $url, string $expectedUrl, array $conf = [], string $ref = 'newref'): void
    {
        $config = new Config();
        $config->merge(['config' => $conf]);

        self::assertSame($expectedUrl, Url::updateDistReference($config, $url, $ref));
    }

    public static function distRefsProvider(): array
    {
        return [
            // github
            ['https://github.com/foo/bar/zipball/abcd',            'https://api.github.com/repos/foo/bar/zipball/newref'],
            ['https://www.github.com/foo/bar/zipball/abcd',        'https://api.github.com/repos/foo/bar/zipball/newref'],
            ['https://github.com/foo/bar/archive/abcd.zip',        'https://api.github.com/repos/foo/bar/zipball/newref'],
            ['https://github.com/foo/bar/archive/abcd.tar.gz',     'https://api.github.com/repos/foo/bar/tarball/newref'],
            ['https://api.github.com/repos/foo/bar/tarball',       'https://api.github.com/repos/foo/bar/tarball/newref'],
            ['https://api.github.com/repos/foo/bar/tarball/abcd',  'https://api.github.com/repos/foo/bar/tarball/newref'],

            // github enterprise
            ['https://mygithub.com/api/v3/repos/foo/bar/tarball/abcd',  'https://mygithub.com/api/v3/repos/foo/bar/tarball/newref', ['github-domains' => ['mygithub.com']]],

            // bitbucket
            ['https://bitbucket.org/foo/bar/get/abcd.zip',         'https://bitbucket.org/foo/bar/get/newref.zip'],
            ['https://www.bitbucket.org/foo/bar/get/abcd.tar.bz2', 'https://bitbucket.org/foo/bar/get/newref.tar.bz2'],

            // gitlab
            ['https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=abcd',       'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=newref'],
            ['https://www.gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=abcd',   'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=newref'],
            ['https://gitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.gz?sha=abcd',    'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=newref'],

            // gitlab enterprise
            ['https://mygitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=abcd',  'https://mygitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=newref', ['gitlab-domains' => ['mygitlab.com']]],
            ['https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=abcd', 'https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=newref', ['gitlab-domains' => ['mygitlab.com']]],
            ['https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=abcd', 'https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=65', ['gitlab-domains' => ['mygitlab.com']], '65'],
        ];
    }

    /**
     * @dataProvider sanitizeProvider
     */
    public function testSanitize(string $expected, string $url): void
    {
        self::assertSame($expected, Url::sanitize($url));
    }

    /**
     * @dataProvider sanitizeProvider
     */
    public function testSanitizeIsIdempotent(string $expected, string $url): void
    {
        self::assertSame($expected, Url::sanitize(Url::sanitize($url)));
    }

    /**
     * @dataProvider isAllowedRedirectProvider
     */
    public function testIsAllowedRedirect(bool $expected, string $url): void
    {
        self::assertSame($expected, Url::isAllowedRedirect($url));
    }

    public static function isAllowedRedirectProvider(): array
    {
        return [
            [true, 'http://example.org/foo'],
            [true, 'https://example.org/foo'],
            [true, 'HTTPS://example.org/foo'],
            [false, 'file://localhost/etc/passwd'],
            [false, 'file:///etc/passwd'],
            [false, 'phar://archive.phar/file'],
            [false, 'data://text/plain;base64,Zm9v'],
            [false, 'ftp://example.org/foo'],
            [false, '/foo/bar'],
            [false, 'example.org/foo'],
        ];
    }

    public static function sanitizeProvider(): array
    {
        return [
            // empty input safe (callers may pass `$x ?? ''` for nullable URLs)
            ['', ''],
            // with scheme
            ['https://foo:***@example.org/', 'https://foo:bar@example.org/'],
            ['https://foo@example.org/', 'https://foo@example.org/'],
            ['https://example.org/', 'https://example.org/'],
            ['http://10a***:***@example.org', 'http://10a8f08e8d7b7b9:foo@example.org'],
            ['https://foo:***@example.org:123/', 'https://foo:bar@example.org:123/'],
            ['https://example.org/foo/bar?access_token=***', 'https://example.org/foo/bar?access_token=abcdef'],
            ['https://example.org/foo/bar?foo=bar&access_token=***', 'https://example.org/foo/bar?foo=bar&access_token=abcdef'],
            ['https://ghp***:***@github.com/acme/repo', 'https://ghp_1234567890abcdefghijklmnopqrstuvwxyzAB:x-oauth-basic@github.com/acme/repo'],
            ['https://git***:***@github.com/acme/repo', 'https://github_pat_1234567890abcdefghijkl_1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW:x-oauth-basic@github.com/acme/repo'],
            ['http://abc***:***@example.org:123/', 'http://abcdefghijkl:bar@example.org:123/'],
            ['https://abc***:***@example.org:123/', 'https://abcdefghijklmnop:bar@example.org:123/'],
            // token/long username in the user slot without a password (e.g. https://TOKEN@host)
            ['https://ghp***@github.com/acme/repo', 'https://ghp_1234567890abcdefghijklmnopqrstuvwxyzAB@github.com/acme/repo'],
            ['https://git***@github.com/acme/repo', 'https://github_pat_1234567890abcdefghijkl_1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW@github.com/acme/repo'],
            ['http://10a***@example.org', 'http://10a8f08e8d7b7b9@example.org'],
            ['https://abc***@example.org:123/', 'https://abcdefghijklmnop@example.org:123/'],
            // well-known non-secret credential markers are shown verbatim even though they are 12char+
            ['https://x-token-auth:***@bitbucket.org/acme/repo', 'https://x-token-auth:secret@bitbucket.org/acme/repo'],
            ['https://gitlab-ci-token:***@gitlab.example.org/', 'https://gitlab-ci-token:realtoken@gitlab.example.org/'],
            // without scheme
            ['foo:***@example.org/', 'foo:bar@example.org/'],
            ['foo@example.org/', 'foo@example.org/'],
            ['example.org/', 'example.org/'],
            ['10a***:***@example.org', '10a8f08e8d7b7b9:foo@example.org'],
            ['foo:***@example.org:123/', 'foo:bar@example.org:123/'],
            ['example.org/foo/bar?access_token=***', 'example.org/foo/bar?access_token=abcdef'],
            ['example.org/foo/bar?foo=bar&access_token=***', 'example.org/foo/bar?foo=bar&access_token=abcdef'],
            ['abc***:***@example.org:123/', 'abcdefghijkl:bar@example.org:123/'],
            ['abc***:***@example.org:123/', 'abcdefghijklmnop:bar@example.org:123/'],
            ['ghp***@github.com/acme/repo', 'ghp_1234567890abcdefghijklmnopqrstuvwxyzAB@github.com/acme/repo'],
            ['10a***@example.org', '10a8f08e8d7b7b9@example.org'],
            ['abc***@example.org:123/', 'abcdefghijklmnop@example.org:123/'],
        ];
    }
}
