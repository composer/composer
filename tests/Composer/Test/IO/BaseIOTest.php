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

namespace Composer\Test\IO;

use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Test\TestCase;

class BaseIOTest extends TestCase
{
    /**
     * @dataProvider provideValidGithubTokens
     */
    public function testLoadConfigurationAcceptsValidGithubToken(string $token): void
    {
        $io = new BufferIO();
        $config = new Config(false);
        $config->merge(['config' => ['github-oauth' => ['github.com' => $token]]]);

        $io->loadConfiguration($config);

        $auth = $io->getAuthentication('github.com');
        self::assertSame($token, $auth['username']);
        self::assertSame('x-oauth-basic', $auth['password']);
    }

    /** @return array<string, array{string}> */
    public static function provideValidGithubTokens(): array
    {
        return [
            'legacy 40-hex PAT' => ['8a7f2c1bdc4e9f06a3b7c2e9d4f1a8b6c5d7e0f2'],
            'ghp_ flat token' => ['ghp_n3K9wQ2eL5bV8mY1pX4cZ7aR0fT6sH3uJ8oI'],
            'gho_ flat token' => ['gho_M2pQ7vR4xL9eK6bN1cT8aZ0sJ3wY5fH7uG2d'],
            'ghu_ flat token' => ['ghu_R5tY8wA1xC4eK7bN0pV3mL6sH9uJ2gD5fQ8z'],
            'ghs_ flat token' => ['ghs_K7bN3pV5mL8eR2tY9wA1xC4sH6uJ0gD3fQ8z'],
            'ghr_ flat token' => ['ghr_X9aZ2sJ5wY8fH1uG4dR7bN0pV3mL6eK2tQ5c'],
            // shivammathur/setup-php style: ghs_<id>_<base64url>.<base64url>.<base64url>
            // base64url alphabet includes '-' which the old regex rejected
            'ghs_ structured installation token (jwt body)' => [
                'ghs_1234567890_eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJjb21wb3NlciJ9.aB-cDef_GHIjkl-mnoPQR0123456',
            ],
            'github_pat_ fine-grained PAT' => [
                'github_pat_11ABCDEFG0aB1cD2eF3gH4_n3K9wQ2eL5bV8mY1pX4cZ7aR0fT6sH3uJ8oI2pQ7vR4xL9eK6bN',
            ],
        ];
    }

    /**
     * @dataProvider provideUrlBreakingGithubTokens
     */
    public function testLoadConfigurationRejectsTokenWithUrlBreakingCharacters(string $token, string $offending): void
    {
        $io = new BufferIO();
        $config = new Config(false);
        $config->merge(['config' => ['github-oauth' => ['github.com' => $token]]]);

        try {
            $io->loadConfiguration($config);
            self::fail('Expected loadConfiguration to reject token containing '.$offending);
        } catch (\UnexpectedValueException $e) {
            // Defect #1: the rejected token must not be echoed back into the
            // exception message — Symfony Console renders it to stderr and CI
            // log shippers / GitHub Actions secret masking do not reliably
            // strip it from the framed error block.
            self::assertStringNotContainsString(
                $token,
                $e->getMessage(),
                'Exception message must not leak the rejected token value.'
            );
        }
    }

    /** @return array<string, array{string, string}> */
    public static function provideUrlBreakingGithubTokens(): array
    {
        return [
            'contains @ (userinfo separator)' => ['ghp_AAAA@evil.example.com', '@'],
            'contains : (basic-auth user:pass split)' => ['ghp_AAAA:extra', ':'],
            'contains / (path separator)' => ['ghp_AAA/BBB', '/'],
            'contains backslash' => ['ghp_AAA\\BBB', '\\'],
            'contains ? (query separator)' => ['ghp_AAA?x=1', '?'],
            'contains # (fragment)' => ['ghp_AAA#frag', '#'],
            'contains space' => ['ghp_AAA BBB', 'space'],
            'contains tab' => ["ghp_AAA\tBBB", 'tab'],
            'contains CR' => ["ghp_AAA\rBBB", 'CR'],
            'contains LF (header injection)' => ["ghp_AAA\nX-Evil: 1", 'LF'],
        ];
    }
}
