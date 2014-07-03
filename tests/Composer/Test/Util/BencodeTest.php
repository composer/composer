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

namespace Composer\Test\Util;

use Composer\Util\Bencode;

/**
 * @author Pádraic Brady <padraic.brady@gmail.com>
 */
class BencodeTest extends \PHPUnit_Framework_TestCase
{
    
    private $json = <<<JSON
{
    "name": "composer/composer",
    "description": "Composer helps you declare, manage and install dependencies of PHP projects, ensuring you have the right stack everywhere.",
    "keywords": ["package", "dependency", "autoload"],
    "homepage": "http://getcomposer.org/",
    "type": "library",
    "license": "MIT",
    "authors": [
        {
            "name": "Nils Adermann",
            "email": "naderman@naderman.de",
            "homepage": "http://www.naderman.de"
        },
        {
            "name": "Jordi Boggiano",
            "email": "j.boggiano@seld.be",
            "homepage": "http://seld.be"
        }
    ],
    "support": {
        "irc": "irc://irc.freenode.org/composer",
        "issues": "https://github.com/composer/composer/issues"
    },
    "require": {
        "php": ">=5.3.2",
        "justinrainbow/json-schema": "~1.1",
        "seld/jsonlint": "1.*",
        "symfony/console": "~2.3",
        "symfony/finder": "~2.2",
        "symfony/process": "~2.1"
    },
    "require-dev": {
        "phpunit/phpunit": "~3.7.10"
    },
    "suggest": {
        "ext-zip": "Enabling the zip extension allows you to unzip archives, and allows gzip compression of all internet traffic",
        "ext-openssl": "Enabling the openssl extension allows you to access https URLs for repositories and packages"
    },
    "autoload": {
        "psr-0": { "Composer": "src/" }
    },
    "autoload-dev": {
        "psr-0": { "Composer\\\\Test": "tests/" }
    },
    "bin": ["bin/composer"],
    "extra": {
        "branch-alias": {
            "dev-master": "1.0-dev"
        }
    }
}
JSON;

    private $expected = <<<EXPECTED
d7:authorsld5:email20:naderman@naderman.de8:homepage22:http://www.naderman.de4:name13:Nils Adermanned5:email18:j.boggiano@seld.be8:homepage14:http://seld.be4:name14:Jordi Boggianoee8:autoloadd5:psr-0d8:Composer4:src/ee12:autoload-devd5:psr-0d13:Composer\Test6:tests/ee3:binl12:bin/composere11:description122:Composer helps you declare, manage and install dependencies of PHP projects, ensuring you have the right stack everywhere.5:extrad12:branch-aliasd10:dev-master7:1.0-devee8:homepage23:http://getcomposer.org/8:keywordsl7:package10:dependency8:autoloade7:license3:MIT4:name17:composer/composer7:required25:justinrainbow/json-schema4:~1.13:php7:>=5.3.213:seld/jsonlint3:1.*15:symfony/console4:~2.314:symfony/finder4:~2.215:symfony/process4:~2.1e11:require-devd15:phpunit/phpunit7:~3.7.10e7:suggestd11:ext-openssl92:Enabling the openssl extension allows you to access https URLs for repositories and packages7:ext-zip108:Enabling the zip extension allows you to unzip archives, and allows gzip compression of all internet traffice7:supportd3:irc31:irc://irc.freenode.org/composer6:issues43:https://github.com/composer/composer/issuese4:type7:librarye
EXPECTED;

    /**
     * @group signing
     */
    public function testEncodingOfComposerJsonAsArray()
    {
        $bencode = new Bencode;
        $actual = $bencode->encode(json_decode($this->json, true));
        $this->assertEquals($this->expected, $actual);
    }

    /**
     * @group signing
     */
    public function testEncodingOfComposerJson()
    {
        $bencode = new Bencode;
        $actual = $bencode->encodeJson($this->json);
        $this->assertEquals($this->expected, $actual);
    }

}
