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

namespace Composer\Test\IO;

use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Test\TestCase;

class BaseIOTest extends TestCase
{
    /**
     * @dataProvider provideValidGithubTokens
     * @param string $token
     */
    public function testLoadConfigurationAcceptsValidGithubToken($token)
    {
        $io = new BufferIO();
        $config = new Config(false);
        $config->merge(array('config' => array('github-oauth' => array('github.com' => $token))));

        $io->loadConfiguration($config);

        $auth = $io->getAuthentication('github.com');
        $this->assertSame($token, $auth['username']);
        $this->assertSame('x-oauth-basic', $auth['password']);
    }

    public function provideValidGithubTokens()
    {
        return array(
            'legacy 40-hex PAT' => array('8a7f2c1bdc4e9f06a3b7c2e9d4f1a8b6c5d7e0f2'),
            'ghp_ flat token' => array('ghp_n3K9wQ2eL5bV8mY1pX4cZ7aR0fT6sH3uJ8oI'),
            'gho_ flat token' => array('gho_M2pQ7vR4xL9eK6bN1cT8aZ0sJ3wY5fH7uG2d'),
            'ghu_ flat token' => array('ghu_R5tY8wA1xC4eK7bN0pV3mL6sH9uJ2gD5fQ8z'),
            'ghs_ flat token' => array('ghs_K7bN3pV5mL8eR2tY9wA1xC4sH6uJ0gD3fQ8z'),
            'ghr_ flat token' => array('ghr_X9aZ2sJ5wY8fH1uG4dR7bN0pV3mL6eK2tQ5c'),
            // shivammathur/setup-php style: ghs_<id>_<base64url>.<base64url>.<base64url>
            // base64url alphabet includes '-' which the old regex rejected
            'ghs_ structured installation token (jwt body)' => array(
                'ghs_1234567890_eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJjb21wb3NlciJ9.aB-cDef_GHIjkl-mnoPQR0123456',
            ),
            'github_pat_ fine-grained PAT' => array(
                'github_pat_11ABCDEFG0aB1cD2eF3gH4_n3K9wQ2eL5bV8mY1pX4cZ7aR0fT6sH3uJ8oI2pQ7vR4xL9eK6bN',
            ),
        );
    }
}
