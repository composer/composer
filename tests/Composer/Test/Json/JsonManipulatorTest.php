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

namespace Composer\Test\Json;

use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Test\TestCase;

class JsonManipulatorTest extends TestCase
{
    /**
     * @dataProvider linkProvider
     */
    public function testAddLink(string $json, string $type, string $package, string $constraint, string $expected): void
    {
        $manipulator = new JsonManipulator($json);
        self::assertTrue($manipulator->addLink($type, $package, $constraint));
        self::assertEquals($expected, $manipulator->getContents());
    }

    public static function linkProvider(): array
    {
        return [
            [
                '{}',
                'require',
                'vendor/baz',
                'qux',
                "{\n".
"    \"require\": {\n".
"        \"vendor/baz\": \"qux\"\n".
"    }\n".
"}\n",
            ],
            [
                '{
    "foo": "bar"
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "foo": "bar",
    "require": {
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require": {
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require": {
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "empty": "",
    "require": {
        "foo": "bar"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "empty": "",
    "require": {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "baz"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],

            [
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "baz"
    }
}',
                'require',
                'vEnDoR/bAz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require":
    {
        "foo": "bar",
        "vendor\/baz": "baz"
    }
}',
                'require',
                'vendor/baz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require":
    {
        "foo": "bar",
        "vendor\/baz": "baz"
    }
}',
                'require',
                'vEnDoR/bAz',
                'qux',
                '{
    "require":
    {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require": {
        "foo": "bar"
    },
    "repositories": [{
        "type": "package",
        "package": {
            "require": {
                "foo": "bar"
            }
        }
    }]
}',
                'require',
                'foo',
                'qux',
                '{
    "require": {
        "foo": "qux"
    },
    "repositories": [{
        "type": "package",
        "package": {
            "require": {
                "foo": "bar"
            }
        }
    }]
}
',
            ],
            [
                '{
    "repositories": [{
        "type": "package",
        "package": {
            "require": {
                "foo": "bar"
            }
        }
    }]
}',
                'require',
                'foo',
                'qux',
                '{
    "repositories": [{
        "type": "package",
        "package": {
            "require": {
                "foo": "bar"
            }
        }
    }],
    "require": {
        "foo": "qux"
    }
}
',
            ],
            [
                '{
    "require": {
        "php": "5.*"
    }
}',
                'require-dev',
                'foo',
                'qux',
                '{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "qux"
    }
}
',
            ],
            [
                '{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "bar"
    }
}',
                'require-dev',
                'foo',
                'qux',
                '{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "qux"
    }
}
',
            ],
            [
                '{
    "repositories": [{
        "type": "package",
        "package": {
            "bar": "ba[z",
            "dist": {
                "url": "http...",
                "type": "zip"
            },
            "autoload": {
                "classmap": [ "foo/bar" ]
            }
        }
    }],
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "bar"
    }
}',
                'require-dev',
                'foo',
                'qux',
                '{
    "repositories": [{
        "type": "package",
        "package": {
            "bar": "ba[z",
            "dist": {
                "url": "http...",
                "type": "zip"
            },
            "autoload": {
                "classmap": [ "foo/bar" ]
            }
        }
    }],
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "qux"
    }
}
',
            ],
            [
                '{
    "config": {
        "cache-files-ttl": 0,
        "discard-changes": true
    },
    "minimum-stability": "stable",
    "prefer-stable": false,
    "provide": {
        "heroku-sys/cedar": "14.2016.03.22"
    },
    "repositories": [
        {
            "packagist.org": false
        },
        {
            "type": "package",
            "package": [
                {
                    "type": "metapackage",
                    "name": "anthonymartin/geo-location",
                    "version": "v1.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "aws/aws-sdk-php",
                    "version": "3.9.4",
                    "require": {
                        "heroku-sys/php": ">=5.5"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "cloudinary/cloudinary_php",
                    "version": "dev-master",
                    "require": {
                        "heroku-sys/ext-curl": "*",
                        "heroku-sys/ext-json": "*",
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/annotations",
                    "version": "v1.2.7",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/cache",
                    "version": "v1.6.0",
                    "require": {
                        "heroku-sys/php": "~5.5|~7.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/collections",
                    "version": "v1.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/common",
                    "version": "v2.6.1",
                    "require": {
                        "heroku-sys/php": "~5.5|~7.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/inflector",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/lexer",
                    "version": "v1.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "geoip/geoip",
                    "version": "v1.16",
                    "require": [],
                    "replace": [],
                    "provide": [],
                    "conflict": {
                        "heroku-sys/ext-geoip": "*"
                    }
                },
                {
                    "type": "metapackage",
                    "name": "giggsey/libphonenumber-for-php",
                    "version": "7.2.5",
                    "require": {
                        "heroku-sys/ext-mbstring": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/guzzle",
                    "version": "5.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/promises",
                    "version": "1.0.3",
                    "require": {
                        "heroku-sys/php": ">=5.5.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/psr7",
                    "version": "1.2.3",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/ringphp",
                    "version": "1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/streams",
                    "version": "3.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "hipchat/hipchat-php",
                    "version": "v1.4",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "kriswallsmith/buzz",
                    "version": "v0.15",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "league/csv",
                    "version": "8.0.0",
                    "require": {
                        "heroku-sys/ext-mbstring": "*",
                        "heroku-sys/php": ">=5.5.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "league/fractal",
                    "version": "0.13.0",
                    "require": {
                        "heroku-sys/php": ">=5.4"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "mashape/unirest-php",
                    "version": "1.2.1",
                    "require": {
                        "heroku-sys/ext-curl": "*",
                        "heroku-sys/ext-json": "*",
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "mtdowling/jmespath.php",
                    "version": "2.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "palex/phpstructureddata",
                    "version": "v2.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "psr/http-message",
                    "version": "1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "react/promise",
                    "version": "v2.2.1",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "rollbar/rollbar",
                    "version": "v0.15.0",
                    "require": {
                        "heroku-sys/ext-curl": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "ronanguilloux/isocodes",
                    "version": "1.2.0",
                    "require": {
                        "heroku-sys/ext-bcmath": "*",
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "sendgrid/sendgrid",
                    "version": "2.1.1",
                    "require": {
                        "heroku-sys/php": ">=5.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "sendgrid/smtpapi",
                    "version": "0.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/css-selector",
                    "version": "v2.8.2",
                    "require": {
                        "heroku-sys/php": ">=5.3.9"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/http-foundation",
                    "version": "v2.8.2",
                    "require": {
                        "heroku-sys/php": ">=5.3.9"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/polyfill-php54",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/polyfill-php55",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "thepixeldeveloper/sitemap",
                    "version": "3.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "tijsverkoyen/css-to-inline-styles",
                    "version": "1.5.5",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "yiisoft/yii",
                    "version": "1.1.17",
                    "require": {
                        "heroku-sys/php": ">=5.1.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "composer.json/composer.lock",
                    "version": "dev-597511d6d51b96e4a8afeba2c79982e5",
                    "require": {
                        "heroku-sys/php": "~5.6.0",
                        "heroku-sys/ext-newrelic": "*",
                        "heroku-sys/ext-gd": "*",
                        "heroku-sys/ext-redis": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                }
            ]
        }
    ],
    "require": {
        "composer.json/composer.lock": "dev-597511d6d51b96e4a8afeba2c79982e5",
        "anthonymartin/geo-location": "v1.0.0",
        "aws/aws-sdk-php": "3.9.4",
        "cloudinary/cloudinary_php": "dev-master",
        "doctrine/annotations": "v1.2.7",
        "doctrine/cache": "v1.6.0",
        "doctrine/collections": "v1.3.0",
        "doctrine/common": "v2.6.1",
        "doctrine/inflector": "v1.1.0",
        "doctrine/lexer": "v1.0.1",
        "geoip/geoip": "v1.16",
        "giggsey/libphonenumber-for-php": "7.2.5",
        "guzzlehttp/guzzle": "5.3.0",
        "guzzlehttp/promises": "1.0.3",
        "guzzlehttp/psr7": "1.2.3",
        "guzzlehttp/ringphp": "1.1.0",
        "guzzlehttp/streams": "3.0.0",
        "hipchat/hipchat-php": "v1.4",
        "kriswallsmith/buzz": "v0.15",
        "league/csv": "8.0.0",
        "league/fractal": "0.13.0",
        "mashape/unirest-php": "1.2.1",
        "mtdowling/jmespath.php": "2.3.0",
        "palex/phpstructureddata": "v2.0.1",
        "psr/http-message": "1.0",
        "react/promise": "v2.2.1",
        "rollbar/rollbar": "v0.15.0",
        "ronanguilloux/isocodes": "1.2.0",
        "sendgrid/sendgrid": "2.1.1",
        "sendgrid/smtpapi": "0.0.1",
        "symfony/css-selector": "v2.8.2",
        "symfony/http-foundation": "v2.8.2",
        "symfony/polyfill-php54": "v1.1.0",
        "symfony/polyfill-php55": "v1.1.0",
        "thepixeldeveloper/sitemap": "3.0.0",
        "tijsverkoyen/css-to-inline-styles": "1.5.5",
        "yiisoft/yii": "1.1.17",
        "heroku-sys/apache": "^2.4.10",
        "heroku-sys/nginx": "~1.8.0"
    }
}',
                'require',
                'foo',
                'qux',
                '{
    "config": {
        "cache-files-ttl": 0,
        "discard-changes": true
    },
    "minimum-stability": "stable",
    "prefer-stable": false,
    "provide": {
        "heroku-sys/cedar": "14.2016.03.22"
    },
    "repositories": [
        {
            "packagist.org": false
        },
        {
            "type": "package",
            "package": [
                {
                    "type": "metapackage",
                    "name": "anthonymartin/geo-location",
                    "version": "v1.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "aws/aws-sdk-php",
                    "version": "3.9.4",
                    "require": {
                        "heroku-sys/php": ">=5.5"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "cloudinary/cloudinary_php",
                    "version": "dev-master",
                    "require": {
                        "heroku-sys/ext-curl": "*",
                        "heroku-sys/ext-json": "*",
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/annotations",
                    "version": "v1.2.7",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/cache",
                    "version": "v1.6.0",
                    "require": {
                        "heroku-sys/php": "~5.5|~7.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/collections",
                    "version": "v1.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/common",
                    "version": "v2.6.1",
                    "require": {
                        "heroku-sys/php": "~5.5|~7.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/inflector",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "doctrine/lexer",
                    "version": "v1.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3.2"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "geoip/geoip",
                    "version": "v1.16",
                    "require": [],
                    "replace": [],
                    "provide": [],
                    "conflict": {
                        "heroku-sys/ext-geoip": "*"
                    }
                },
                {
                    "type": "metapackage",
                    "name": "giggsey/libphonenumber-for-php",
                    "version": "7.2.5",
                    "require": {
                        "heroku-sys/ext-mbstring": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/guzzle",
                    "version": "5.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/promises",
                    "version": "1.0.3",
                    "require": {
                        "heroku-sys/php": ">=5.5.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/psr7",
                    "version": "1.2.3",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/ringphp",
                    "version": "1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "guzzlehttp/streams",
                    "version": "3.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "hipchat/hipchat-php",
                    "version": "v1.4",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "kriswallsmith/buzz",
                    "version": "v0.15",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "league/csv",
                    "version": "8.0.0",
                    "require": {
                        "heroku-sys/ext-mbstring": "*",
                        "heroku-sys/php": ">=5.5.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "league/fractal",
                    "version": "0.13.0",
                    "require": {
                        "heroku-sys/php": ">=5.4"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "mashape/unirest-php",
                    "version": "1.2.1",
                    "require": {
                        "heroku-sys/ext-curl": "*",
                        "heroku-sys/ext-json": "*",
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "mtdowling/jmespath.php",
                    "version": "2.3.0",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "palex/phpstructureddata",
                    "version": "v2.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "psr/http-message",
                    "version": "1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "react/promise",
                    "version": "v2.2.1",
                    "require": {
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "rollbar/rollbar",
                    "version": "v0.15.0",
                    "require": {
                        "heroku-sys/ext-curl": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "ronanguilloux/isocodes",
                    "version": "1.2.0",
                    "require": {
                        "heroku-sys/ext-bcmath": "*",
                        "heroku-sys/php": ">=5.4.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "sendgrid/sendgrid",
                    "version": "2.1.1",
                    "require": {
                        "heroku-sys/php": ">=5.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "sendgrid/smtpapi",
                    "version": "0.0.1",
                    "require": {
                        "heroku-sys/php": ">=5.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/css-selector",
                    "version": "v2.8.2",
                    "require": {
                        "heroku-sys/php": ">=5.3.9"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/http-foundation",
                    "version": "v2.8.2",
                    "require": {
                        "heroku-sys/php": ">=5.3.9"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/polyfill-php54",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "symfony/polyfill-php55",
                    "version": "v1.1.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.3"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "thepixeldeveloper/sitemap",
                    "version": "3.0.0",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "tijsverkoyen/css-to-inline-styles",
                    "version": "1.5.5",
                    "require": {
                        "heroku-sys/php": ">=5.3.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "yiisoft/yii",
                    "version": "1.1.17",
                    "require": {
                        "heroku-sys/php": ">=5.1.0"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                },
                {
                    "type": "metapackage",
                    "name": "composer.json/composer.lock",
                    "version": "dev-597511d6d51b96e4a8afeba2c79982e5",
                    "require": {
                        "heroku-sys/php": "~5.6.0",
                        "heroku-sys/ext-newrelic": "*",
                        "heroku-sys/ext-gd": "*",
                        "heroku-sys/ext-redis": "*"
                    },
                    "replace": [],
                    "provide": [],
                    "conflict": []
                }
            ]
        }
    ],
    "require": {
        "composer.json/composer.lock": "dev-597511d6d51b96e4a8afeba2c79982e5",
        "anthonymartin/geo-location": "v1.0.0",
        "aws/aws-sdk-php": "3.9.4",
        "cloudinary/cloudinary_php": "dev-master",
        "doctrine/annotations": "v1.2.7",
        "doctrine/cache": "v1.6.0",
        "doctrine/collections": "v1.3.0",
        "doctrine/common": "v2.6.1",
        "doctrine/inflector": "v1.1.0",
        "doctrine/lexer": "v1.0.1",
        "geoip/geoip": "v1.16",
        "giggsey/libphonenumber-for-php": "7.2.5",
        "guzzlehttp/guzzle": "5.3.0",
        "guzzlehttp/promises": "1.0.3",
        "guzzlehttp/psr7": "1.2.3",
        "guzzlehttp/ringphp": "1.1.0",
        "guzzlehttp/streams": "3.0.0",
        "hipchat/hipchat-php": "v1.4",
        "kriswallsmith/buzz": "v0.15",
        "league/csv": "8.0.0",
        "league/fractal": "0.13.0",
        "mashape/unirest-php": "1.2.1",
        "mtdowling/jmespath.php": "2.3.0",
        "palex/phpstructureddata": "v2.0.1",
        "psr/http-message": "1.0",
        "react/promise": "v2.2.1",
        "rollbar/rollbar": "v0.15.0",
        "ronanguilloux/isocodes": "1.2.0",
        "sendgrid/sendgrid": "2.1.1",
        "sendgrid/smtpapi": "0.0.1",
        "symfony/css-selector": "v2.8.2",
        "symfony/http-foundation": "v2.8.2",
        "symfony/polyfill-php54": "v1.1.0",
        "symfony/polyfill-php55": "v1.1.0",
        "thepixeldeveloper/sitemap": "3.0.0",
        "tijsverkoyen/css-to-inline-styles": "1.5.5",
        "yiisoft/yii": "1.1.17",
        "heroku-sys/apache": "^2.4.10",
        "heroku-sys/nginx": "~1.8.0",
        "foo": "qux"
    }
}
',
            ],
        ];
    }

    /**
     * @dataProvider providerAddLinkAndSortPackages
     */
    public function testAddLinkAndSortPackages(string $json, string $type, string $package, string $constraint, bool $sortPackages, string $expected): void
    {
        $manipulator = new JsonManipulator($json);
        self::assertTrue($manipulator->addLink($type, $package, $constraint, $sortPackages));
        self::assertEquals($expected, $manipulator->getContents());
    }

    public static function providerAddLinkAndSortPackages(): array
    {
        return [
            [
                '{
    "require": {
        "vendor/baz": "qux"
    }
}',
                'require',
                'foo',
                'bar',
                true,
                '{
    "require": {
        "foo": "bar",
        "vendor/baz": "qux"
    }
}
',
            ],
            [
                '{
    "require": {
        "vendor/baz": "qux"
    }
}',
                'require',
                'foo',
                'bar',
                false,
                '{
    "require": {
        "vendor/baz": "qux",
        "foo": "bar"
    }
}
',
            ],
            [
                '{
    "require": {
        "foo": "baz",
        "ext-10gd": "*",
        "ext-2mcrypt": "*",
        "lib-foo": "*",
        "hhvm": "*",
        "php": ">=5.5"
    }
}',
                'require',
                'igorw/retry',
                '*',
                true,
                '{
    "require": {
        "php": ">=5.5",
        "hhvm": "*",
        "ext-2mcrypt": "*",
        "ext-10gd": "*",
        "lib-foo": "*",
        "foo": "baz",
        "igorw/retry": "*"
    }
}
',
            ],
        ];
    }

    /**
     * @dataProvider removeSubNodeProvider
     */
    public function testRemoveSubNode(string $json, string $name, bool $expected, ?string $expectedContent = null): void
    {
        $manipulator = new JsonManipulator($json);

        self::assertEquals($expected, $manipulator->removeSubNode('repositories', $name));
        if (null !== $expectedContent) {
            self::assertEquals($expectedContent, $manipulator->getContents());
        }
    }

    public static function removeSubNodeProvider(): array
    {
        return [
            'works on simple ones first' => [
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        },
        "bar": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'foo',
                true,
                '{
    "repositories": {
        "bar": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}
',
            ],
            'works on simple ones last' => [
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        },
        "bar": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'bar',
                true,
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}
',
            ],
            'works on simple ones unique' => [
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'foo',
                true,
                '{
    "repositories": {
    }
}
',
            ],
            'works on simple ones escaped slash' => [
                '{
    "repositories": {
        "foo\/bar": {
            "bar": "baz"
        }
    }
}',
                'foo/bar',
                true,
                '{
    "repositories": {
    }
}
',
            ],
            'works on simple ones middle' => [
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        },
        "bar": {
            "foo": "bar",
            "bar": "baz"
        },
        "baz": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'bar',
                true,
                '{
    "repositories": {
        "foo": {
            "foo": "bar",
            "bar": "baz"
        },
        "baz": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}
',
            ],
            'works on undefined ones' => [
                '{
    "repositories": {
        "main": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'removenotthere',
                true,
                '{
    "repositories": {
        "main": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}
',
            ],
            'works on child having unmatched name' => [
                '{
    "repositories": {
        "baz": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'bar',
                true,
                '{
    "repositories": {
        "baz": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}
',
            ],
            'works on child having duplicate name' => [
                '{
    "repositories": {
        "foo": {
            "baz": "qux"
        },
        "baz": {
            "foo": "bar",
            "bar": "baz"
        }
    }
}',
                'baz',
                true,
                '{
    "repositories": {
        "foo": {
            "baz": "qux"
        }
    }
}
',
            ],
            'works on empty repos' => [
                '{
    "repositories": {
    }
}',
                'bar',
                true,
            ],
            'works on empty repos2' => [
                '{
    "repositories": {}
}',
                'bar',
                true,
            ],
            'works on missing repos' => [
                "{\n}",
                'bar',
                true,
            ],
            'works on deep repos' => [
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "baz" }
        }
    }
}',
                'foo',
                true,
                '{
    "repositories": {
    }
}
',
            ],
            'works on deep repos with borked texts' => [
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba{z" }
        }
    }
}',
                'bar',
                true,
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba{z" }
        }
    }
}
',

                '{
}
',
            ],
            'works on deep repos with borked texts2' => [
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba}z" }
        }
    }
}',
                'bar',
                true,
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba}z" }
        }
    }
}
',

                '{
}
',
            ],
            'fails on deep arrays with borked texts' => [
                '{
    "repositories": [
        {
            "package": { "bar": "ba[z" }
        }
    ]
}',
                'bar',
                false,
            ],
            'fails on deep arrays with borked texts2' => [
                '{
    "repositories": [
        {
            "package": { "bar": "ba]z" }
        }
    ]
}',
                'bar',
                false,
            ],
        ];
    }

    public function testRemoveSubNodeFromRequire(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "package": {
                "require": {
                    "this/should-not-end-up-in-root-require": "~2.0"
                },
                "require-dev": {
                    "this/should-not-end-up-in-root-require-dev": "~2.0"
                }
            }
        }
    ],
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "require-dev": {
        "package/d": "*"
    }
}');

        self::assertTrue($manipulator->removeSubNode('require', 'package/c'));
        self::assertTrue($manipulator->removeSubNode('require-dev', 'package/d'));
        self::assertEquals('{
    "repositories": [
        {
            "package": {
                "require": {
                    "this/should-not-end-up-in-root-require": "~2.0"
                },
                "require-dev": {
                    "this/should-not-end-up-in-root-require-dev": "~2.0"
                }
            }
        }
    ],
    "require": {
        "package/a": "*",
        "package/b": "*"
    },
    "require-dev": {
    }
}
', $manipulator->getContents());
    }

    public function testRemoveSubNodePreservesObjectTypeWhenEmpty(): void
    {
        $manipulator = new JsonManipulator('{
    "test": {"0": "foo"}
}');

        self::assertTrue($manipulator->removeSubNode('test', '0'));
        self::assertEquals('{
    "test": {
    }
}
', $manipulator->getContents());
    }

    public function testRemoveSubNodePreservesObjectTypeWhenEmpty2(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "preferred-install": {"foo/*": "source"}
    }
}');

        self::assertTrue($manipulator->removeConfigSetting('preferred-install.foo/*'));
        self::assertEquals('{
    "config": {
        "preferred-install": {
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddSubNodeInRequire(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "package": {
                "require": {
                    "this/should-not-end-up-in-root-require": "~2.0"
                },
                "require-dev": {
                    "this/should-not-end-up-in-root-require-dev": "~2.0"
                }
            }
        }
    ],
    "require": {
        "package/a": "*",
        "package/b": "*"
    },
    "require-dev": {
        "package/d": "*"
    }
}');

        self::assertTrue($manipulator->addSubNode('require', 'package/c', '*'));
        self::assertTrue($manipulator->addSubNode('require-dev', 'package/e', '*'));
        self::assertEquals('{
    "repositories": [
        {
            "package": {
                "require": {
                    "this/should-not-end-up-in-root-require": "~2.0"
                },
                "require-dev": {
                    "this/should-not-end-up-in-root-require-dev": "~2.0"
                }
            }
        }
    ],
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "require-dev": {
        "package/d": "*",
        "package/e": "*"
    }
}
', $manipulator->getContents());
    }

    public function testAddExtraWithPackage(): void
    {
        //$this->markTestSkipped();
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "extra": {
        "auto-append-gitignore": true
    }
}');

        self::assertTrue($manipulator->addProperty('extra.foo-bar', true));
        self::assertEquals('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "extra": {
        "auto-append-gitignore": true,
        "foo-bar": true
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigWithPackage(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "config": {
        "platform": {
            "php": "5.3.9"
        }
    }
}');

        self::assertTrue($manipulator->addConfigSetting('preferred-install.my-organization/stable-package', 'dist'));
        self::assertEquals('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "config": {
        "platform": {
            "php": "5.3.9"
        },
        "preferred-install": {
            "my-organization/stable-package": "dist"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddSuggestWithPackage(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "suggest": {
        "package": "Description"
    }
}');

        self::assertTrue($manipulator->addProperty('suggest.new-package', 'new-description'));
        self::assertEquals('{
    "repositories": [
        {
            "type": "package",
            "package": {
                "authors": [],
                "extra": {
                    "package-xml": "package.xml"
                }
            }
        }
    ],
    "suggest": {
        "package": "Description",
        "new-package": "new-description"
    }
}
', $manipulator->getContents());
    }

    public function testAddRepositoryCanInitializeEmptyRepositories(): void
    {
        $manipulator = new JsonManipulator('{
  "repositories": {
  }
}');

        self::assertTrue($manipulator->addRepository('bar', ['type' => 'composer']));
        self::assertEquals('{
  "repositories": {
    "bar": {
      "type": "composer"
    }
  }
}
', $manipulator->getContents());
    }

    public function testAddRepositoryCanInitializeFromScratch(): void
    {
        $manipulator = new JsonManipulator("{
\t\"a\": \"b\"
}");

        self::assertTrue($manipulator->addRepository('bar2', ['type' => 'composer']));
        self::assertEquals("{
\t\"a\": \"b\",
\t\"repositories\": {
\t\t\"bar2\": {
\t\t\t\"type\": \"composer\"
\t\t}
\t}
}
", $manipulator->getContents());
    }

    public function testAddRepositoryCanAppend(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": {
        "foo": {
            "type": "vcs",
            "url": "lala"
        }
    }
}');

        self::assertTrue($manipulator->addRepository('bar', ['type' => 'composer'], true));
        self::assertEquals('{
    "repositories": {
        "foo": {
            "type": "vcs",
            "url": "lala"
        },
        "bar": {
            "type": "composer"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddRepositoryCanPrepend(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": {
        "foo": {
            "type": "vcs",
            "url": "lala"
        }
    }
}');

        self::assertTrue($manipulator->addRepository('bar', ['type' => 'composer'], false));
        self::assertEquals('{
    "repositories": {
        "bar": {
            "type": "composer"
        },
        "foo": {
            "type": "vcs",
            "url": "lala"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddRepositoryCanOverrideDeepRepos(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": {
        "baz": {
            "type": "package",
            "package": {}
        }
    }
}');

        self::assertTrue($manipulator->addRepository('baz', ['type' => 'composer']));
        self::assertEquals('{
    "repositories": {
        "baz": {
            "type": "composer"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingEscapes(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
    }
}');

        self::assertTrue($manipulator->addConfigSetting('test', 'a\b'));
        self::assertTrue($manipulator->addConfigSetting('test2', "a\nb\fa"));
        self::assertEquals('{
    "config": {
        "test": "a\\\\b",
        "test2": "a\nb\fa"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingWorksFromScratch(): void
    {
        $manipulator = new JsonManipulator('{
}');

        self::assertTrue($manipulator->addConfigSetting('foo.bar', 'baz'));
        self::assertEquals('{
    "config": {
        "foo": {
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAdd(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": "bar"
    }
}');

        self::assertTrue($manipulator->addConfigSetting('bar', 'baz'));
        self::assertEquals('{
    "config": {
        "foo": "bar",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwrite(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": "bar",
        "bar": "baz"
    }
}');

        self::assertTrue($manipulator->addConfigSetting('foo', 'zomg'));
        self::assertEquals('{
    "config": {
        "foo": "zomg",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwriteNumbers(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": 500
    }
}');

        self::assertTrue($manipulator->addConfigSetting('foo', 50));
        self::assertEquals('{
    "config": {
        "foo": 50
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwriteArrays(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        },
        "github-protocols": ["https"]
    }
}');

        self::assertTrue($manipulator->addConfigSetting('github-protocols', ['https', 'http']));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        },
        "github-protocols": ["https", "http"]
    }
}
', $manipulator->getContents());

        self::assertTrue($manipulator->addConfigSetting('github-oauth', ['github.com' => 'bar', 'alt.example.org' => 'baz']));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "bar",
            "alt.example.org": "baz"
        },
        "github-protocols": ["https", "http"]
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAddSubKeyInEmptyConfig(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
    }
}');

        self::assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAddSubKeyInEmptyVal(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {},
        "github-oauth2": {
        }
    }
}');

        self::assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        self::assertTrue($manipulator->addConfigSetting('github-oauth2.a.bar', 'baz2'));
        self::assertTrue($manipulator->addConfigSetting('github-oauth3.b', 'c'));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "bar": "baz"
        },
        "github-oauth2": {
            "a.bar": "baz2"
        },
        "github-oauth3": {
            "b": "c"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAddSubKeyInHash(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        }
    }
}');

        self::assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo",
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddRootSettingDoesNotBreakDots(): void
    {
        $manipulator = new JsonManipulator('{
    "github-oauth": {
        "github.com": "foo"
    }
}');

        self::assertTrue($manipulator->addSubNode('github-oauth', 'bar', 'baz'));
        self::assertEquals('{
    "github-oauth": {
        "github.com": "foo",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testRemoveConfigSettingCanRemoveSubKeyInHash(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo",
            "bar": "baz"
        }
    }
}');

        self::assertTrue($manipulator->removeConfigSetting('github-oauth.bar'));
        self::assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        }
    }
}
', $manipulator->getContents());
    }

    public function testRemoveConfigSettingCanRemoveSubKeyInHashWithSiblings(): void
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": "bar",
        "github-oauth": {
            "github.com": "foo",
            "bar": "baz"
        }
    }
}');

        self::assertTrue($manipulator->removeConfigSetting('github-oauth.bar'));
        self::assertEquals('{
    "config": {
        "foo": "bar",
        "github-oauth": {
            "github.com": "foo"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddMainKey(): void
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        self::assertTrue($manipulator->addMainKey('bar', 'baz'));
        self::assertEquals('{
    "foo": "bar",
    "bar": "baz"
}
', $manipulator->getContents());
    }

    public function testAddMainKeyWithContentHavingDollarSignFollowedByDigit(): void
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        self::assertTrue($manipulator->addMainKey('bar', '$1baz'));
        self::assertEquals('{
    "foo": "bar",
    "bar": "$1baz"
}
', $manipulator->getContents());
    }

    public function testAddMainKeyWithContentHavingDollarSignFollowedByDigit2(): void
    {
        $manipulator = new JsonManipulator('{}');

        self::assertTrue($manipulator->addMainKey('foo', '$1bar'));
        self::assertEquals('{
    "foo": "$1bar"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey(): void
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        self::assertTrue($manipulator->addMainKey('foo', 'baz'));
        self::assertEquals('{
    "foo": "baz"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey2(): void
    {
        $manipulator = new JsonManipulator('{
    "a": {
        "foo": "bar",
        "baz": "qux"
    },
    "foo": "bar",
    "baz": "bar"
}');

        self::assertTrue($manipulator->addMainKey('foo', 'baz'));
        self::assertTrue($manipulator->addMainKey('baz', 'quux'));
        self::assertEquals('{
    "a": {
        "foo": "bar",
        "baz": "qux"
    },
    "foo": "baz",
    "baz": "quux"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey3(): void
    {
        $manipulator = new JsonManipulator('{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "bar"
    }
}');

        self::assertTrue($manipulator->addMainKey('require-dev', ['foo' => 'qux']));
        self::assertEquals('{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "qux"
    }
}
', $manipulator->getContents());
    }

    public function testUpdateMainKeyWithContentHavingDollarSignFollowedByDigit(): void
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        self::assertTrue($manipulator->addMainKey('foo', '$1bar'));
        self::assertEquals('{
    "foo": "$1bar"
}
', $manipulator->getContents());
    }

    public function testRemoveMainKey(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
        {
            "package": {
                "require": {
                    "this/should-not-end-up-in-root-require": "~2.0"
                },
                "require-dev": {
                    "this/should-not-end-up-in-root-require-dev": "~2.0"
                }
            }
        }
    ],
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "foo": "bar",
    "require-dev": {
        "package/d": "*"
    }
}');

        self::assertTrue($manipulator->removeMainKey('repositories'));
        self::assertEquals('{
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "foo": "bar",
    "require-dev": {
        "package/d": "*"
    }
}
', $manipulator->getContents());

        self::assertTrue($manipulator->removeMainKey('foo'));
        self::assertEquals('{
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "require-dev": {
        "package/d": "*"
    }
}
', $manipulator->getContents());

        self::assertTrue($manipulator->removeMainKey('require'));
        self::assertTrue($manipulator->removeMainKey('require-dev'));
        self::assertEquals('{
}
', $manipulator->getContents());
    }

    public function testRemoveMainKeyIfEmpty(): void
    {
        $manipulator = new JsonManipulator('{
    "repositories": [
    ],
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "foo": "bar",
    "require-dev": {
    }
}');

        self::assertTrue($manipulator->removeMainKeyIfEmpty('repositories'));
        self::assertEquals('{
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "foo": "bar",
    "require-dev": {
    }
}
', $manipulator->getContents());

        self::assertTrue($manipulator->removeMainKeyIfEmpty('foo'));
        self::assertTrue($manipulator->removeMainKeyIfEmpty('require'));
        self::assertTrue($manipulator->removeMainKeyIfEmpty('require-dev'));
        self::assertEquals('{
    "require": {
        "package/a": "*",
        "package/b": "*",
        "package/c": "*"
    },
    "foo": "bar"
}
', $manipulator->getContents());
    }

    public function testRemoveMainKeyRemovesKeyWhereValueIsNull(): void
    {
        $manipulator = new JsonManipulator(json_encode([
            'foo' => 9000,
            'bar' => null,
        ]));

        $manipulator->removeMainKey('bar');

        $expected = JsonFile::encode([
            'foo' => 9000,
        ]);

        self::assertJsonStringEqualsJsonString($expected, $manipulator->getContents());
    }

    public function testIndentDetection(): void
    {
        $manipulator = new JsonManipulator('{

  "require": {
    "php": "5.*"
  }
}');

        self::assertTrue($manipulator->addMainKey('require-dev', ['foo' => 'qux']));
        self::assertEquals('{

  "require": {
    "php": "5.*"
  },
  "require-dev": {
    "foo": "qux"
  }
}
', $manipulator->getContents());
    }

    public function testRemoveMainKeyAtEndOfFile(): void
    {
        $manipulator = new JsonManipulator('{
    "require": {
        "package/a": "*"
    }
}
');
        self::assertTrue($manipulator->addMainKey('homepage', 'http...'));
        self::assertTrue($manipulator->addMainKey('license', 'mit'));
        self::assertEquals('{
    "require": {
        "package/a": "*"
    },
    "homepage": "http...",
    "license": "mit"
}
', $manipulator->getContents());

        self::assertTrue($manipulator->removeMainKey('homepage'));
        self::assertTrue($manipulator->removeMainKey('license'));
        self::assertEquals('{
    "require": {
        "package/a": "*"
    }
}
', $manipulator->getContents());
    }

    /**
     * @dataProvider addListItemProvider
     * @param array<string, mixed>|false $value
     */
    public function testAddListItem(string $from, string $to, string $mainNode, $value, bool $append): void
    {
        $manipulator = new JsonManipulator($from);

        self::assertTrue($manipulator->addListItem($mainNode, $value, $append));
        self::assertEquals($to, $manipulator->getContents());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>|false, 4: bool}>
     */
    public static function addListItemProvider(): iterable
    {
        yield 'int-array first append to unset' => [
            '{}',
            '{
    "main": [1]
}
',
            'main',
            1,
            true
        ];
        yield 'int-array (one-line) first append to empty' => [
            '{
    "main": [ ]
}
',
            '{
    "main": [1]
}
',
            'main',
            1,
            true
        ];
        yield 'int-array (one-line) second append to non-empty-list' => [
            '{
    "main": [ 1 ]
}
',
            '{
    "main": [ 1, 2 ]
}
',
            'main',
            2,
            true
        ];
        yield 'object-array first append to unset' => [
            '{}',
            '{
    "main": [{
        "value": 1
    }]
}
',
            'main',
            ['value' => 1],
            true
        ];
        yield 'object-array (one-line) first append to empty' => [
            '{
    "main": [ ]
}
',
            '{
    "main": [{
        "value": 2
    }]
}
',
            'main',
            ['value' => 2],
            true
        ];
        yield 'mixed-array (one-line) second append to non-empty-list' => [
            '{
    "main": [ 1 ]
}
',
            '{
    "main": [ 1, {
        "value": 2
    } ]
}
',
            'main',
            ['value' => 2],
            true
        ];

        yield 'int-array (multi-line) first append to empty' => [
            '{
    "main": [
    ]
}
',
            '{
    "main": [
        1
    ]
}
',
            'main',
            1,
            true
        ];
        yield 'int-array (multi-line) second append to non-empty-list' => [
            '{
    "main": [
        1
    ]
}
',
            '{
    "main": [
        1,
        2
    ]
}
',
            'main',
            2,
            true
        ];
        yield 'object-array (multi-line) first append to empty' => [
            '{
    "main": [
    ]
}
',
            '{
    "main": [
        {
            "value": 1
        }
    ]
}
',
            'main',
            ['value' => 1],
            true
        ];
        yield 'mixed-array (multi-line) second append to non-empty-list' => [
            '{
    "main": [
        1
    ]
}
',
            '{
    "main": [
        1,
        {
            "value": 2
        }
    ]
}
',
            'main',
            ['value' => 2],
            true
        ];

        yield 'int-array first prepend to unset' => [
            '{}',
            '{
    "main": [1]
}
',
            'main',
            1,
            false
        ];
        yield 'int-array (one-line) first prepend to empty' => [
            '{
    "main": [ ]
}
',
            '{
    "main": [1]
}
',
            'main',
            1,
            false
        ];
        yield 'int-array (one-line) second prepend to non-empty-list' => [
            '{
    "main": [ 1 ]
}
',
            '{
    "main": [ 2, 1 ]
}
',
            'main',
            2,
            false
        ];
        yield 'object-array first prepend to unset' => [
            '{}',
            '{
    "main": [{
        "value": 1
    }]
}
',
            'main',
            ['value' => 1],
            false
        ];
        yield 'object-array (one-line) first prepend to empty' => [
            '{
    "main": [ ]
}
',
            '{
    "main": [{
        "value": 1
    }]
}
',
            'main',
            ['value' => 1],
            false
        ];
        yield 'mixed-array (one-line) second prepend to non-empty-list' => [
            '{
    "main": [ 1 ]
}
',
            '{
    "main": [ {
        "value": 2
    }, 1 ]
}
',
            'main',
            ['value' => 2],
            false
        ];

        yield 'int-array (multi-line) first prepend to empty' => [
            '{
    "main": [
    ]
}
',
            '{
    "main": [
        1
    ]
}
',
            'main',
            1,
            false
        ];
        yield 'int-array (multi-line) second prepend to non-empty-list' => [
            '{
    "main": [
        1
    ]
}
',
            '{
    "main": [
        2,
        1
    ]
}
',
            'main',
            2,
            false
        ];
        yield 'object-array (multi-line) first prepend to empty' => [
            '{
    "main": [
    ]
}
',
            '{
    "main": [
        {
            "value": 1
        }
    ]
}
',
            'main',
            ['value' => 1],
            false
        ];
        yield 'mixed-array (multi-line) second prepend to non-empty-list' => [
            '{
    "main": [
        1
    ]
}
',
            '{
    "main": [
        {
            "value": 2
        },
        1
    ]
}
',
            'main',
            ['value' => 2],
            false
        ];
    }

    /**
     * @dataProvider removeListItemProvider
     */
    public function testRemoveListItem(string $from, string $to, string $mainNode, int $indexToRemove): void
    {
        $manipulator = new JsonManipulator($from);

        self::assertTrue($manipulator->removeListItem($mainNode, $indexToRemove));
        self::assertEquals($to, $manipulator->getContents());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function removeListItemProvider(): iterable
    {
        yield 'int-array (one-line) remove first' => [
            '{
    "main": [
        1, 2, 3
    ]
}',
            '{
    "main": [
        2, 3
    ]
}
',
            'main',
            0
        ];
        yield 'int-array (one-line) remove middle' => [
            '{
    "main": [
        1, 2, 3
    ]
}',
            '{
    "main": [
        1, 3
    ]
}
',
            'main',
            1
        ];
        yield 'int-array (one-line) remove last' => [
            '{
    "main": [
        1, 2, 3
    ]
}',
            '{
    "main": [
        1, 2
    ]
}
',
            'main',
            2
        ];
        yield 'int-array (multi-line) remove first' => [
            '{
    "main": [
        1,
        2,
        3
    ]
}',
            '{
    "main": [
        2,
        3
    ]
}
',
            'main',
            0
        ];
        yield 'int-array (multi-line) remove middle' => [
            '{
    "main": [
        1,
        2,
        3
    ]
}',
            '{
    "main": [
        1,
        3
    ]
}
',
            'main',
            1
        ];
        yield 'int-array (multi-line) remove last' => [
            '{
    "main": [
        1,
        2,
        3
    ]
}',
            '{
    "main": [
        1,
        2
    ]
}
',
            'main',
            2
        ];
    }

    /**
     * @dataProvider insertListItemProvider
     * @param array<string, mixed> $value
     */
    public function testInsertListItem(string $from, string $to, string $mainNode, array $value, int $indexToInsertAt): void
    {
        $manipulator = new JsonManipulator($from);

        self::assertTrue($manipulator->insertListItem($mainNode, $value, $indexToInsertAt));
        self::assertEquals($to, $manipulator->getContents());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: int}>
     */
    public static function insertListItemProvider(): iterable
    {
        yield 'insert at 0 to unset list' => [
            '{
}
',
            '{
    "main": [{
        "foo": 1
    }]
}
',
            'main',
            ['foo' => 1],
            0,
        ];
        yield 'insert at 0 to empty list' => [
            '{
    "main": [
    ]
}
',
            '{
    "main": [
        {
            "foo": 1
        }
    ]
}
',
            'main',
            ['foo' => 1],
            0,
        ];
        yield 'insert at 0 list of 2' => [
            '{
    "main": [
        {
            "foo": 2
        },
        {
            "foo": 4
        }
    ]
}
',
            '{
    "main": [
        {
            "foo": 1
        },
        {
            "foo": 2
        },
        {
            "foo": 4
        }
    ]
}
',
            'main',
            ['foo' => 1],
            0,
        ];
        yield 'insert at 1 list of 2' => [
            '{
    "main": [
        {
            "foo": 2
        },
        {
            "foo": 4
        }
    ]
}
',
            '{
    "main": [
        {
            "foo": 2
        },
        {
            "foo": 3
        },
        {
            "foo": 4
        }
    ]
}
',
            'main',
            ['foo' => 3],
            1,
        ];
        yield 'insert at 2 list of 2' => [
            '{
    "main": [
        {
            "foo": 2
        },
        {
            "foo": 4
        }
    ]
}
',
            '{
    "main": [
        {
            "foo": 2
        },
        {
            "foo": 4
        },
        {
            "foo": 5
        }
    ]
}
',
            'main',
            ['foo' => 5],
            2,
        ];
    }

    public function testEscapedUnicodeDoesNotCauseBacktrackLimitErrorGithubIssue8131(): void
    {
        $manipulator = new JsonManipulator('{
  "description": "Some U\u00F1icode",
  "require": {
    "foo/bar": "^1.0"
  }
}');

        self::assertTrue($manipulator->addLink('require', 'foo/baz', '^1.0'));
        self::assertEquals('{
  "description": "Some U\u00F1icode",
  "require": {
    "foo/bar": "^1.0",
    "foo/baz": "^1.0"
  }
}
', $manipulator->getContents());
    }

    public function testLargeFileDoesNotCauseBacktrackLimitErrorGithubIssue9595(): void
    {
        $manipulator = new JsonManipulator('{
    "name": "leoloso/pop",
    "require": {
        "php": "^7.4|^8.0",
        "ext-mbstring": "*",
        "brain/cortex": "~1.0.0",
        "composer/installers": "~1.0",
        "composer/semver": "^1.5",
        "erusev/parsedown": "^1.7",
        "guzzlehttp/guzzle": "~6.3",
        "jrfnl/php-cast-to-type": "^2.0",
        "league/pipeline": "^1.0",
        "lkwdwrd/wp-muplugin-loader": "dev-feature-composer-v2",
        "obsidian/polyfill-hrtime": "^0.1",
        "psr/cache": "^1.0",
        "symfony/cache": "^5.1",
        "symfony/config": "^5.1",
        "symfony/dependency-injection": "^5.1",
        "symfony/dotenv": "^5.1",
        "symfony/expression-language": "^5.1",
        "symfony/polyfill-php72": "^1.18",
        "symfony/polyfill-php73": "^1.18",
        "symfony/polyfill-php74": "^1.18",
        "symfony/polyfill-php80": "^1.18",
        "symfony/property-access": "^5.1",
        "symfony/yaml": "^5.1"
    },
    "require-dev": {
        "johnpbloch/wordpress": ">=5.5",
        "phpstan/phpstan": "^0.12",
        "phpunit/phpunit": ">=9.3",
        "rector/rector": "^0.9",
        "squizlabs/php_codesniffer": "^3.0",
        "symfony/var-dumper": "^5.1",
        "symplify/monorepo-builder": "^9.0",
        "szepeviktor/phpstan-wordpress": "^0.6.2"
    },
    "autoload": {
        "psr-4": {
            "GraphQLAPI\\\\ConvertCaseDirectives\\\\": "layers/GraphQLAPIForWP/plugins/convert-case-directives/src",
            "GraphQLAPI\\\\GraphQLAPI\\\\": "layers/GraphQLAPIForWP/plugins/graphql-api-for-wp/src",
            "GraphQLAPI\\\\SchemaFeedback\\\\": "layers/GraphQLAPIForWP/plugins/schema-feedback/src",
            "GraphQLByPoP\\\\GraphQLClientsForWP\\\\": "layers/GraphQLByPoP/packages/graphql-clients-for-wp/src",
            "GraphQLByPoP\\\\GraphQLEndpointForWP\\\\": "layers/GraphQLByPoP/packages/graphql-endpoint-for-wp/src",
            "GraphQLByPoP\\\\GraphQLParser\\\\": "layers/GraphQLByPoP/packages/graphql-parser/src",
            "GraphQLByPoP\\\\GraphQLQuery\\\\": "layers/GraphQLByPoP/packages/graphql-query/src",
            "GraphQLByPoP\\\\GraphQLRequest\\\\": "layers/GraphQLByPoP/packages/graphql-request/src",
            "GraphQLByPoP\\\\GraphQLServer\\\\": "layers/GraphQLByPoP/packages/graphql-server/src",
            "Leoloso\\\\ExamplesForPoP\\\\": "layers/Misc/packages/examples-for-pop/src",
            "PoPSchema\\\\BasicDirectives\\\\": "layers/Schema/packages/basic-directives/src",
            "PoPSchema\\\\BlockMetadataWP\\\\": "layers/Schema/packages/block-metadata-for-wp/src",
            "PoPSchema\\\\CDNDirective\\\\": "layers/Schema/packages/cdn-directive/src",
            "PoPSchema\\\\CategoriesWP\\\\": "layers/Schema/packages/categories-wp/src",
            "PoPSchema\\\\Categories\\\\": "layers/Schema/packages/categories/src",
            "PoPSchema\\\\CommentMetaWP\\\\": "layers/Schema/packages/commentmeta-wp/src",
            "PoPSchema\\\\CommentMeta\\\\": "layers/Schema/packages/commentmeta/src",
            "PoPSchema\\\\CommentMutationsWP\\\\": "layers/Schema/packages/comment-mutations-wp/src",
            "PoPSchema\\\\CommentMutations\\\\": "layers/Schema/packages/comment-mutations/src",
            "PoPSchema\\\\CommentsWP\\\\": "layers/Schema/packages/comments-wp/src",
            "PoPSchema\\\\Comments\\\\": "layers/Schema/packages/comments/src",
            "PoPSchema\\\\ConvertCaseDirectives\\\\": "layers/Schema/packages/convert-case-directives/src",
            "PoPSchema\\\\CustomPostMediaMutationsWP\\\\": "layers/Schema/packages/custompostmedia-mutations-wp/src",
            "PoPSchema\\\\CustomPostMediaMutations\\\\": "layers/Schema/packages/custompostmedia-mutations/src",
            "PoPSchema\\\\CustomPostMediaWP\\\\": "layers/Schema/packages/custompostmedia-wp/src",
            "PoPSchema\\\\CustomPostMedia\\\\": "layers/Schema/packages/custompostmedia/src",
            "PoPSchema\\\\CustomPostMetaWP\\\\": "layers/Schema/packages/custompostmeta-wp/src",
            "PoPSchema\\\\CustomPostMeta\\\\": "layers/Schema/packages/custompostmeta/src",
            "PoPSchema\\\\CustomPostMutationsWP\\\\": "layers/Schema/packages/custompost-mutations-wp/src",
            "PoPSchema\\\\CustomPostMutations\\\\": "layers/Schema/packages/custompost-mutations/src",
            "PoPSchema\\\\CustomPostsWP\\\\": "layers/Schema/packages/customposts-wp/src",
            "PoPSchema\\\\CustomPosts\\\\": "layers/Schema/packages/customposts/src",
            "PoPSchema\\\\EventMutationsWPEM\\\\": "layers/Schema/packages/event-mutations-wp-em/src",
            "PoPSchema\\\\EventMutations\\\\": "layers/Schema/packages/event-mutations/src",
            "PoPSchema\\\\EventsWPEM\\\\": "layers/Schema/packages/events-wp-em/src",
            "PoPSchema\\\\Events\\\\": "layers/Schema/packages/events/src",
            "PoPSchema\\\\EverythingElseWP\\\\": "layers/Schema/packages/everythingelse-wp/src",
            "PoPSchema\\\\EverythingElse\\\\": "layers/Schema/packages/everythingelse/src",
            "PoPSchema\\\\GenericCustomPosts\\\\": "layers/Schema/packages/generic-customposts/src",
            "PoPSchema\\\\GoogleTranslateDirectiveForCustomPosts\\\\": "layers/Schema/packages/google-translate-directive-for-customposts/src",
            "PoPSchema\\\\GoogleTranslateDirective\\\\": "layers/Schema/packages/google-translate-directive/src",
            "PoPSchema\\\\HighlightsWP\\\\": "layers/Schema/packages/highlights-wp/src",
            "PoPSchema\\\\Highlights\\\\": "layers/Schema/packages/highlights/src",
            "PoPSchema\\\\LocationPostsWP\\\\": "layers/Schema/packages/locationposts-wp/src",
            "PoPSchema\\\\LocationPosts\\\\": "layers/Schema/packages/locationposts/src",
            "PoPSchema\\\\LocationsWPEM\\\\": "layers/Schema/packages/locations-wp-em/src",
            "PoPSchema\\\\Locations\\\\": "layers/Schema/packages/locations/src",
            "PoPSchema\\\\MediaWP\\\\": "layers/Schema/packages/media-wp/src",
            "PoPSchema\\\\Media\\\\": "layers/Schema/packages/media/src",
            "PoPSchema\\\\MenusWP\\\\": "layers/Schema/packages/menus-wp/src",
            "PoPSchema\\\\Menus\\\\": "layers/Schema/packages/menus/src",
            "PoPSchema\\\\MetaQueryWP\\\\": "layers/Schema/packages/metaquery-wp/src",
            "PoPSchema\\\\MetaQuery\\\\": "layers/Schema/packages/metaquery/src",
            "PoPSchema\\\\Meta\\\\": "layers/Schema/packages/meta/src",
            "PoPSchema\\\\NotificationsWP\\\\": "layers/Schema/packages/notifications-wp/src",
            "PoPSchema\\\\Notifications\\\\": "layers/Schema/packages/notifications/src",
            "PoPSchema\\\\PagesWP\\\\": "layers/Schema/packages/pages-wp/src",
            "PoPSchema\\\\Pages\\\\": "layers/Schema/packages/pages/src",
            "PoPSchema\\\\PostMutations\\\\": "layers/Schema/packages/post-mutations/src",
            "PoPSchema\\\\PostTagsWP\\\\": "layers/Schema/packages/post-tags-wp/src",
            "PoPSchema\\\\PostTags\\\\": "layers/Schema/packages/post-tags/src",
            "PoPSchema\\\\PostsWP\\\\": "layers/Schema/packages/posts-wp/src",
            "PoPSchema\\\\Posts\\\\": "layers/Schema/packages/posts/src",
            "PoPSchema\\\\QueriedObjectWP\\\\": "layers/Schema/packages/queriedobject-wp/src",
            "PoPSchema\\\\QueriedObject\\\\": "layers/Schema/packages/queriedobject/src",
            "PoPSchema\\\\SchemaCommons\\\\": "layers/Schema/packages/schema-commons/src",
            "PoPSchema\\\\StancesWP\\\\": "layers/Schema/packages/stances-wp/src",
            "PoPSchema\\\\Stances\\\\": "layers/Schema/packages/stances/src",
            "PoPSchema\\\\TagsWP\\\\": "layers/Schema/packages/tags-wp/src",
            "PoPSchema\\\\Tags\\\\": "layers/Schema/packages/tags/src",
            "PoPSchema\\\\TaxonomiesWP\\\\": "layers/Schema/packages/taxonomies-wp/src",
            "PoPSchema\\\\Taxonomies\\\\": "layers/Schema/packages/taxonomies/src",
            "PoPSchema\\\\TaxonomyMetaWP\\\\": "layers/Schema/packages/taxonomymeta-wp/src",
            "PoPSchema\\\\TaxonomyMeta\\\\": "layers/Schema/packages/taxonomymeta/src",
            "PoPSchema\\\\TaxonomyQueryWP\\\\": "layers/Schema/packages/taxonomyquery-wp/src",
            "PoPSchema\\\\TaxonomyQuery\\\\": "layers/Schema/packages/taxonomyquery/src",
            "PoPSchema\\\\TranslateDirectiveACL\\\\": "layers/Schema/packages/translate-directive-acl/src",
            "PoPSchema\\\\TranslateDirective\\\\": "layers/Schema/packages/translate-directive/src",
            "PoPSchema\\\\UserMetaWP\\\\": "layers/Schema/packages/usermeta-wp/src",
            "PoPSchema\\\\UserMeta\\\\": "layers/Schema/packages/usermeta/src",
            "PoPSchema\\\\UserRolesACL\\\\": "layers/Schema/packages/user-roles-acl/src",
            "PoPSchema\\\\UserRolesAccessControl\\\\": "layers/Schema/packages/user-roles-access-control/src",
            "PoPSchema\\\\UserRolesWP\\\\": "layers/Schema/packages/user-roles-wp/src",
            "PoPSchema\\\\UserRoles\\\\": "layers/Schema/packages/user-roles/src",
            "PoPSchema\\\\UserStateAccessControl\\\\": "layers/Schema/packages/user-state-access-control/src",
            "PoPSchema\\\\UserStateMutationsWP\\\\": "layers/Schema/packages/user-state-mutations-wp/src",
            "PoPSchema\\\\UserStateMutations\\\\": "layers/Schema/packages/user-state-mutations/src",
            "PoPSchema\\\\UserStateWP\\\\": "layers/Schema/packages/user-state-wp/src",
            "PoPSchema\\\\UserState\\\\": "layers/Schema/packages/user-state/src",
            "PoPSchema\\\\UsersWP\\\\": "layers/Schema/packages/users-wp/src",
            "PoPSchema\\\\Users\\\\": "layers/Schema/packages/users/src",
            "PoPSitesWassup\\\\CommentMutations\\\\": "layers/Wassup/packages/comment-mutations/src",
            "PoPSitesWassup\\\\ContactUsMutations\\\\": "layers/Wassup/packages/contactus-mutations/src",
            "PoPSitesWassup\\\\ContactUserMutations\\\\": "layers/Wassup/packages/contactuser-mutations/src",
            "PoPSitesWassup\\\\CustomPostLinkMutations\\\\": "layers/Wassup/packages/custompostlink-mutations/src",
            "PoPSitesWassup\\\\CustomPostMutations\\\\": "layers/Wassup/packages/custompost-mutations/src",
            "PoPSitesWassup\\\\EventLinkMutations\\\\": "layers/Wassup/packages/eventlink-mutations/src",
            "PoPSitesWassup\\\\EventMutations\\\\": "layers/Wassup/packages/event-mutations/src",
            "PoPSitesWassup\\\\EverythingElseMutations\\\\": "layers/Wassup/packages/everythingelse-mutations/src",
            "PoPSitesWassup\\\\FlagMutations\\\\": "layers/Wassup/packages/flag-mutations/src",
            "PoPSitesWassup\\\\FormMutations\\\\": "layers/Wassup/packages/form-mutations/src",
            "PoPSitesWassup\\\\GravityFormsMutations\\\\": "layers/Wassup/packages/gravityforms-mutations/src",
            "PoPSitesWassup\\\\HighlightMutations\\\\": "layers/Wassup/packages/highlight-mutations/src",
            "PoPSitesWassup\\\\LocationMutations\\\\": "layers/Wassup/packages/location-mutations/src",
            "PoPSitesWassup\\\\LocationPostLinkMutations\\\\": "layers/Wassup/packages/locationpostlink-mutations/src",
            "PoPSitesWassup\\\\LocationPostMutations\\\\": "layers/Wassup/packages/locationpost-mutations/src",
            "PoPSitesWassup\\\\NewsletterMutations\\\\": "layers/Wassup/packages/newsletter-mutations/src",
            "PoPSitesWassup\\\\NotificationMutations\\\\": "layers/Wassup/packages/notification-mutations/src",
            "PoPSitesWassup\\\\PostLinkMutations\\\\": "layers/Wassup/packages/postlink-mutations/src",
            "PoPSitesWassup\\\\PostMutations\\\\": "layers/Wassup/packages/post-mutations/src",
            "PoPSitesWassup\\\\ShareMutations\\\\": "layers/Wassup/packages/share-mutations/src",
            "PoPSitesWassup\\\\SocialNetworkMutations\\\\": "layers/Wassup/packages/socialnetwork-mutations/src",
            "PoPSitesWassup\\\\StanceMutations\\\\": "layers/Wassup/packages/stance-mutations/src",
            "PoPSitesWassup\\\\SystemMutations\\\\": "layers/Wassup/packages/system-mutations/src",
            "PoPSitesWassup\\\\UserStateMutations\\\\": "layers/Wassup/packages/user-state-mutations/src",
            "PoPSitesWassup\\\\VolunteerMutations\\\\": "layers/Wassup/packages/volunteer-mutations/src",
            "PoPSitesWassup\\\\Wassup\\\\": "layers/Wassup/packages/wassup/src",
            "PoP\\\\APIClients\\\\": "layers/API/packages/api-clients/src",
            "PoP\\\\APIEndpointsForWP\\\\": "layers/API/packages/api-endpoints-for-wp/src",
            "PoP\\\\APIEndpoints\\\\": "layers/API/packages/api-endpoints/src",
            "PoP\\\\APIMirrorQuery\\\\": "layers/API/packages/api-mirrorquery/src",
            "PoP\\\\API\\\\": "layers/API/packages/api/src",
            "PoP\\\\AccessControl\\\\": "layers/Engine/packages/access-control/src",
            "PoP\\\\ApplicationWP\\\\": "layers/SiteBuilder/packages/application-wp/src",
            "PoP\\\\Application\\\\": "layers/SiteBuilder/packages/application/src",
            "PoP\\\\Base36Definitions\\\\": "layers/SiteBuilder/packages/definitions-base36/src",
            "PoP\\\\CacheControl\\\\": "layers/Engine/packages/cache-control/src",
            "PoP\\\\ComponentModel\\\\": "layers/Engine/packages/component-model/src",
            "PoP\\\\ConfigurableSchemaFeedback\\\\": "layers/Engine/packages/configurable-schema-feedback/src",
            "PoP\\\\ConfigurationComponentModel\\\\": "layers/SiteBuilder/packages/component-model-configuration/src",
            "PoP\\\\DefinitionPersistence\\\\": "layers/SiteBuilder/packages/definitionpersistence/src",
            "PoP\\\\Definitions\\\\": "layers/Engine/packages/definitions/src",
            "PoP\\\\EmojiDefinitions\\\\": "layers/SiteBuilder/packages/definitions-emoji/src",
            "PoP\\\\EngineWP\\\\": "layers/Engine/packages/engine-wp/src",
            "PoP\\\\Engine\\\\": "layers/Engine/packages/engine/src",
            "PoP\\\\FieldQuery\\\\": "layers/Engine/packages/field-query/src",
            "PoP\\\\FileStore\\\\": "layers/Engine/packages/filestore/src",
            "PoP\\\\FunctionFields\\\\": "layers/Engine/packages/function-fields/src",
            "PoP\\\\GraphQLAPI\\\\": "layers/API/packages/api-graphql/src",
            "PoP\\\\GuzzleHelpers\\\\": "layers/Engine/packages/guzzle-helpers/src",
            "PoP\\\\HooksWP\\\\": "layers/Engine/packages/hooks-wp/src",
            "PoP\\\\Hooks\\\\": "layers/Engine/packages/hooks/src",
            "PoP\\\\LooseContracts\\\\": "layers/Engine/packages/loosecontracts/src",
            "PoP\\\\MandatoryDirectivesByConfiguration\\\\": "layers/Engine/packages/mandatory-directives-by-configuration/src",
            "PoP\\\\ModuleRouting\\\\": "layers/Engine/packages/modulerouting/src",
            "PoP\\\\Multisite\\\\": "layers/SiteBuilder/packages/multisite/src",
            "PoP\\\\PoP\\\\": "src",
            "PoP\\\\QueryParsing\\\\": "layers/Engine/packages/query-parsing/src",
            "PoP\\\\RESTAPI\\\\": "layers/API/packages/api-rest/src",
            "PoP\\\\ResourceLoader\\\\": "layers/SiteBuilder/packages/resourceloader/src",
            "PoP\\\\Resources\\\\": "layers/SiteBuilder/packages/resources/src",
            "PoP\\\\Root\\\\": "layers/Engine/packages/root/src",
            "PoP\\\\RoutingWP\\\\": "layers/Engine/packages/routing-wp/src",
            "PoP\\\\Routing\\\\": "layers/Engine/packages/routing/src",
            "PoP\\\\SPA\\\\": "layers/SiteBuilder/packages/spa/src",
            "PoP\\\\SSG\\\\": "layers/SiteBuilder/packages/static-site-generator/src",
            "PoP\\\\SiteWP\\\\": "layers/SiteBuilder/packages/site-wp/src",
            "PoP\\\\Site\\\\": "layers/SiteBuilder/packages/site/src",
            "PoP\\\\TraceTools\\\\": "layers/Engine/packages/trace-tools/src",
            "PoP\\\\TranslationWP\\\\": "layers/Engine/packages/translation-wp/src",
            "PoP\\\\Translation\\\\": "layers/Engine/packages/translation/src"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "GraphQLAPI\\\\ConvertCaseDirectives\\\\": "layers/GraphQLAPIForWP/plugins/convert-case-directives/tests",
            "GraphQLAPI\\\\GraphQLAPI\\\\": "layers/GraphQLAPIForWP/plugins/graphql-api-for-wp/tests",
            "GraphQLAPI\\\\SchemaFeedback\\\\": "layers/GraphQLAPIForWP/plugins/schema-feedback/tests",
            "GraphQLByPoP\\\\GraphQLClientsForWP\\\\": "layers/GraphQLByPoP/packages/graphql-clients-for-wp/tests",
            "GraphQLByPoP\\\\GraphQLEndpointForWP\\\\": "layers/GraphQLByPoP/packages/graphql-endpoint-for-wp/tests",
            "GraphQLByPoP\\\\GraphQLParser\\\\": "layers/GraphQLByPoP/packages/graphql-parser/tests",
            "GraphQLByPoP\\\\GraphQLQuery\\\\": "layers/GraphQLByPoP/packages/graphql-query/tests",
            "GraphQLByPoP\\\\GraphQLRequest\\\\": "layers/GraphQLByPoP/packages/graphql-request/tests",
            "GraphQLByPoP\\\\GraphQLServer\\\\": "layers/GraphQLByPoP/packages/graphql-server/tests",
            "Leoloso\\\\ExamplesForPoP\\\\": "layers/Misc/packages/examples-for-pop/tests",
            "PoPSchema\\\\BasicDirectives\\\\": "layers/Schema/packages/basic-directives/tests",
            "PoPSchema\\\\BlockMetadataWP\\\\": "layers/Schema/packages/block-metadata-for-wp/tests",
            "PoPSchema\\\\CDNDirective\\\\": "layers/Schema/packages/cdn-directive/tests",
            "PoPSchema\\\\CategoriesWP\\\\": "layers/Schema/packages/categories-wp/tests",
            "PoPSchema\\\\Categories\\\\": "layers/Schema/packages/categories/tests",
            "PoPSchema\\\\CommentMetaWP\\\\": "layers/Schema/packages/commentmeta-wp/tests",
            "PoPSchema\\\\CommentMeta\\\\": "layers/Schema/packages/commentmeta/tests",
            "PoPSchema\\\\CommentMutationsWP\\\\": "layers/Schema/packages/comment-mutations-wp/tests",
            "PoPSchema\\\\CommentMutations\\\\": "layers/Schema/packages/comment-mutations/tests",
            "PoPSchema\\\\CommentsWP\\\\": "layers/Schema/packages/comments-wp/tests",
            "PoPSchema\\\\Comments\\\\": "layers/Schema/packages/comments/tests",
            "PoPSchema\\\\ConvertCaseDirectives\\\\": "layers/Schema/packages/convert-case-directives/tests",
            "PoPSchema\\\\CustomPostMediaMutationsWP\\\\": "layers/Schema/packages/custompostmedia-mutations-wp/tests",
            "PoPSchema\\\\CustomPostMediaMutations\\\\": "layers/Schema/packages/custompostmedia-mutations/tests",
            "PoPSchema\\\\CustomPostMediaWP\\\\": "layers/Schema/packages/custompostmedia-wp/tests",
            "PoPSchema\\\\CustomPostMedia\\\\": "layers/Schema/packages/custompostmedia/tests",
            "PoPSchema\\\\CustomPostMetaWP\\\\": "layers/Schema/packages/custompostmeta-wp/tests",
            "PoPSchema\\\\CustomPostMeta\\\\": "layers/Schema/packages/custompostmeta/tests",
            "PoPSchema\\\\CustomPostMutationsWP\\\\": "layers/Schema/packages/custompost-mutations-wp/tests",
            "PoPSchema\\\\CustomPostMutations\\\\": "layers/Schema/packages/custompost-mutations/tests",
            "PoPSchema\\\\CustomPostsWP\\\\": "layers/Schema/packages/customposts-wp/tests",
            "PoPSchema\\\\CustomPosts\\\\": "layers/Schema/packages/customposts/tests",
            "PoPSchema\\\\EventMutationsWPEM\\\\": "layers/Schema/packages/event-mutations-wp-em/tests",
            "PoPSchema\\\\EventMutations\\\\": "layers/Schema/packages/event-mutations/tests",
            "PoPSchema\\\\EventsWPEM\\\\": "layers/Schema/packages/events-wp-em/tests",
            "PoPSchema\\\\Events\\\\": "layers/Schema/packages/events/tests",
            "PoPSchema\\\\EverythingElseWP\\\\": "layers/Schema/packages/everythingelse-wp/tests",
            "PoPSchema\\\\EverythingElse\\\\": "layers/Schema/packages/everythingelse/tests",
            "PoPSchema\\\\GenericCustomPosts\\\\": "layers/Schema/packages/generic-customposts/tests",
            "PoPSchema\\\\GoogleTranslateDirectiveForCustomPosts\\\\": "layers/Schema/packages/google-translate-directive-for-customposts/tests",
            "PoPSchema\\\\GoogleTranslateDirective\\\\": "layers/Schema/packages/google-translate-directive/tests",
            "PoPSchema\\\\HighlightsWP\\\\": "layers/Schema/packages/highlights-wp/tests",
            "PoPSchema\\\\Highlights\\\\": "layers/Schema/packages/highlights/tests",
            "PoPSchema\\\\LocationPostsWP\\\\": "layers/Schema/packages/locationposts-wp/tests",
            "PoPSchema\\\\LocationPosts\\\\": "layers/Schema/packages/locationposts/tests",
            "PoPSchema\\\\LocationsWPEM\\\\": "layers/Schema/packages/locations-wp-em/tests",
            "PoPSchema\\\\Locations\\\\": "layers/Schema/packages/locations/tests",
            "PoPSchema\\\\MediaWP\\\\": "layers/Schema/packages/media-wp/tests",
            "PoPSchema\\\\Media\\\\": "layers/Schema/packages/media/tests",
            "PoPSchema\\\\MenusWP\\\\": "layers/Schema/packages/menus-wp/tests",
            "PoPSchema\\\\Menus\\\\": "layers/Schema/packages/menus/tests",
            "PoPSchema\\\\MetaQueryWP\\\\": "layers/Schema/packages/metaquery-wp/tests",
            "PoPSchema\\\\MetaQuery\\\\": "layers/Schema/packages/metaquery/tests",
            "PoPSchema\\\\Meta\\\\": "layers/Schema/packages/meta/tests",
            "PoPSchema\\\\NotificationsWP\\\\": "layers/Schema/packages/notifications-wp/tests",
            "PoPSchema\\\\Notifications\\\\": "layers/Schema/packages/notifications/tests",
            "PoPSchema\\\\PagesWP\\\\": "layers/Schema/packages/pages-wp/tests",
            "PoPSchema\\\\Pages\\\\": "layers/Schema/packages/pages/tests",
            "PoPSchema\\\\PostMutations\\\\": "layers/Schema/packages/post-mutations/tests",
            "PoPSchema\\\\PostTagsWP\\\\": "layers/Schema/packages/post-tags-wp/tests",
            "PoPSchema\\\\PostTags\\\\": "layers/Schema/packages/post-tags/tests",
            "PoPSchema\\\\PostsWP\\\\": "layers/Schema/packages/posts-wp/tests",
            "PoPSchema\\\\Posts\\\\": "layers/Schema/packages/posts/tests",
            "PoPSchema\\\\QueriedObjectWP\\\\": "layers/Schema/packages/queriedobject-wp/tests",
            "PoPSchema\\\\QueriedObject\\\\": "layers/Schema/packages/queriedobject/tests",
            "PoPSchema\\\\SchemaCommons\\\\": "layers/Schema/packages/schema-commons/tests",
            "PoPSchema\\\\StancesWP\\\\": "layers/Schema/packages/stances-wp/tests",
            "PoPSchema\\\\Stances\\\\": "layers/Schema/packages/stances/tests",
            "PoPSchema\\\\TagsWP\\\\": "layers/Schema/packages/tags-wp/tests",
            "PoPSchema\\\\Tags\\\\": "layers/Schema/packages/tags/tests",
            "PoPSchema\\\\TaxonomiesWP\\\\": "layers/Schema/packages/taxonomies-wp/tests",
            "PoPSchema\\\\Taxonomies\\\\": "layers/Schema/packages/taxonomies/tests",
            "PoPSchema\\\\TaxonomyMetaWP\\\\": "layers/Schema/packages/taxonomymeta-wp/tests",
            "PoPSchema\\\\TaxonomyMeta\\\\": "layers/Schema/packages/taxonomymeta/tests",
            "PoPSchema\\\\TaxonomyQueryWP\\\\": "layers/Schema/packages/taxonomyquery-wp/tests",
            "PoPSchema\\\\TaxonomyQuery\\\\": "layers/Schema/packages/taxonomyquery/tests",
            "PoPSchema\\\\TranslateDirectiveACL\\\\": "layers/Schema/packages/translate-directive-acl/tests",
            "PoPSchema\\\\TranslateDirective\\\\": "layers/Schema/packages/translate-directive/tests",
            "PoPSchema\\\\UserMetaWP\\\\": "layers/Schema/packages/usermeta-wp/tests",
            "PoPSchema\\\\UserMeta\\\\": "layers/Schema/packages/usermeta/tests",
            "PoPSchema\\\\UserRolesACL\\\\": "layers/Schema/packages/user-roles-acl/tests",
            "PoPSchema\\\\UserRolesAccessControl\\\\": "layers/Schema/packages/user-roles-access-control/tests",
            "PoPSchema\\\\UserRolesWP\\\\": "layers/Schema/packages/user-roles-wp/tests",
            "PoPSchema\\\\UserRoles\\\\": "layers/Schema/packages/user-roles/tests",
            "PoPSchema\\\\UserStateAccessControl\\\\": "layers/Schema/packages/user-state-access-control/tests",
            "PoPSchema\\\\UserStateMutationsWP\\\\": "layers/Schema/packages/user-state-mutations-wp/tests",
            "PoPSchema\\\\UserStateMutations\\\\": "layers/Schema/packages/user-state-mutations/tests",
            "PoPSchema\\\\UserStateWP\\\\": "layers/Schema/packages/user-state-wp/tests",
            "PoPSchema\\\\UserState\\\\": "layers/Schema/packages/user-state/tests",
            "PoPSchema\\\\UsersWP\\\\": "layers/Schema/packages/users-wp/tests",
            "PoPSchema\\\\Users\\\\": "layers/Schema/packages/users/tests",
            "PoPSitesWassup\\\\CommentMutations\\\\": "layers/Wassup/packages/comment-mutations/tests",
            "PoPSitesWassup\\\\ContactUsMutations\\\\": "layers/Wassup/packages/contactus-mutations/tests",
            "PoPSitesWassup\\\\ContactUserMutations\\\\": "layers/Wassup/packages/contactuser-mutations/tests",
            "PoPSitesWassup\\\\CustomPostLinkMutations\\\\": "layers/Wassup/packages/custompostlink-mutations/tests",
            "PoPSitesWassup\\\\CustomPostMutations\\\\": "layers/Wassup/packages/custompost-mutations/tests",
            "PoPSitesWassup\\\\EventLinkMutations\\\\": "layers/Wassup/packages/eventlink-mutations/tests",
            "PoPSitesWassup\\\\EventMutations\\\\": "layers/Wassup/packages/event-mutations/tests",
            "PoPSitesWassup\\\\EverythingElseMutations\\\\": "layers/Wassup/packages/everythingelse-mutations/tests",
            "PoPSitesWassup\\\\FlagMutations\\\\": "layers/Wassup/packages/flag-mutations/tests",
            "PoPSitesWassup\\\\FormMutations\\\\": "layers/Wassup/packages/form-mutations/tests",
            "PoPSitesWassup\\\\GravityFormsMutations\\\\": "layers/Wassup/packages/gravityforms-mutations/tests",
            "PoPSitesWassup\\\\HighlightMutations\\\\": "layers/Wassup/packages/highlight-mutations/tests",
            "PoPSitesWassup\\\\LocationMutations\\\\": "layers/Wassup/packages/location-mutations/tests",
            "PoPSitesWassup\\\\LocationPostLinkMutations\\\\": "layers/Wassup/packages/locationpostlink-mutations/tests",
            "PoPSitesWassup\\\\LocationPostMutations\\\\": "layers/Wassup/packages/locationpost-mutations/tests",
            "PoPSitesWassup\\\\NewsletterMutations\\\\": "layers/Wassup/packages/newsletter-mutations/tests",
            "PoPSitesWassup\\\\NotificationMutations\\\\": "layers/Wassup/packages/notification-mutations/tests",
            "PoPSitesWassup\\\\PostLinkMutations\\\\": "layers/Wassup/packages/postlink-mutations/tests",
            "PoPSitesWassup\\\\PostMutations\\\\": "layers/Wassup/packages/post-mutations/tests",
            "PoPSitesWassup\\\\ShareMutations\\\\": "layers/Wassup/packages/share-mutations/tests",
            "PoPSitesWassup\\\\SocialNetworkMutations\\\\": "layers/Wassup/packages/socialnetwork-mutations/tests",
            "PoPSitesWassup\\\\StanceMutations\\\\": "layers/Wassup/packages/stance-mutations/tests",
            "PoPSitesWassup\\\\SystemMutations\\\\": "layers/Wassup/packages/system-mutations/tests",
            "PoPSitesWassup\\\\UserStateMutations\\\\": "layers/Wassup/packages/user-state-mutations/tests",
            "PoPSitesWassup\\\\VolunteerMutations\\\\": "layers/Wassup/packages/volunteer-mutations/tests",
            "PoPSitesWassup\\\\Wassup\\\\": "layers/Wassup/packages/wassup/tests",
            "PoP\\\\APIClients\\\\": "layers/API/packages/api-clients/tests",
            "PoP\\\\APIEndpointsForWP\\\\": "layers/API/packages/api-endpoints-for-wp/tests",
            "PoP\\\\APIEndpoints\\\\": "layers/API/packages/api-endpoints/tests",
            "PoP\\\\APIMirrorQuery\\\\": "layers/API/packages/api-mirrorquery/tests",
            "PoP\\\\API\\\\": "layers/API/packages/api/tests",
            "PoP\\\\AccessControl\\\\": "layers/Engine/packages/access-control/tests",
            "PoP\\\\ApplicationWP\\\\": "layers/SiteBuilder/packages/application-wp/tests",
            "PoP\\\\Application\\\\": "layers/SiteBuilder/packages/application/tests",
            "PoP\\\\Base36Definitions\\\\": "layers/SiteBuilder/packages/definitions-base36/tests",
            "PoP\\\\CacheControl\\\\": "layers/Engine/packages/cache-control/tests",
            "PoP\\\\ComponentModel\\\\": "layers/Engine/packages/component-model/tests",
            "PoP\\\\ConfigurableSchemaFeedback\\\\": "layers/Engine/packages/configurable-schema-feedback/tests",
            "PoP\\\\ConfigurationComponentModel\\\\": "layers/SiteBuilder/packages/component-model-configuration/tests",
            "PoP\\\\DefinitionPersistence\\\\": "layers/SiteBuilder/packages/definitionpersistence/tests",
            "PoP\\\\Definitions\\\\": "layers/Engine/packages/definitions/tests",
            "PoP\\\\EmojiDefinitions\\\\": "layers/SiteBuilder/packages/definitions-emoji/tests",
            "PoP\\\\EngineWP\\\\": "layers/Engine/packages/engine-wp/tests",
            "PoP\\\\Engine\\\\": "layers/Engine/packages/engine/tests",
            "PoP\\\\FieldQuery\\\\": "layers/Engine/packages/field-query/tests",
            "PoP\\\\FileStore\\\\": "layers/Engine/packages/filestore/tests",
            "PoP\\\\FunctionFields\\\\": "layers/Engine/packages/function-fields/tests",
            "PoP\\\\GraphQLAPI\\\\": "layers/API/packages/api-graphql/tests",
            "PoP\\\\GuzzleHelpers\\\\": "layers/Engine/packages/guzzle-helpers/tests",
            "PoP\\\\HooksWP\\\\": "layers/Engine/packages/hooks-wp/tests",
            "PoP\\\\Hooks\\\\": "layers/Engine/packages/hooks/tests",
            "PoP\\\\LooseContracts\\\\": "layers/Engine/packages/loosecontracts/tests",
            "PoP\\\\MandatoryDirectivesByConfiguration\\\\": "layers/Engine/packages/mandatory-directives-by-configuration/tests",
            "PoP\\\\ModuleRouting\\\\": "layers/Engine/packages/modulerouting/tests",
            "PoP\\\\Multisite\\\\": "layers/SiteBuilder/packages/multisite/tests",
            "PoP\\\\QueryParsing\\\\": "layers/Engine/packages/query-parsing/tests",
            "PoP\\\\RESTAPI\\\\": "layers/API/packages/api-rest/tests",
            "PoP\\\\ResourceLoader\\\\": "layers/SiteBuilder/packages/resourceloader/tests",
            "PoP\\\\Resources\\\\": "layers/SiteBuilder/packages/resources/tests",
            "PoP\\\\Root\\\\": "layers/Engine/packages/root/tests",
            "PoP\\\\RoutingWP\\\\": "layers/Engine/packages/routing-wp/tests",
            "PoP\\\\Routing\\\\": "layers/Engine/packages/routing/tests",
            "PoP\\\\SPA\\\\": "layers/SiteBuilder/packages/spa/tests",
            "PoP\\\\SSG\\\\": "layers/SiteBuilder/packages/static-site-generator/tests",
            "PoP\\\\SiteWP\\\\": "layers/SiteBuilder/packages/site-wp/tests",
            "PoP\\\\Site\\\\": "layers/SiteBuilder/packages/site/tests",
            "PoP\\\\TraceTools\\\\": "layers/Engine/packages/trace-tools/tests",
            "PoP\\\\TranslationWP\\\\": "layers/Engine/packages/translation-wp/tests",
            "PoP\\\\Translation\\\\": "layers/Engine/packages/translation/tests"
        }
    },
    "extra": {
        "wordpress-install-dir": "vendor/wordpress/wordpress",
        "merge-plugin": {
            "include": [
                "composer.local.json"
            ],
            "recurse": true,
            "replace": false,
            "ignore-duplicates": false,
            "merge-dev": true,
            "merge-extra": false,
            "merge-extra-deep": false,
            "merge-scripts": false
        }
    },
    "replace": {
        "getpop/access-control": "self.version",
        "getpop/api": "self.version",
        "getpop/api-clients": "self.version",
        "getpop/api-endpoints": "self.version",
        "getpop/api-endpoints-for-wp": "self.version",
        "getpop/api-graphql": "self.version",
        "getpop/api-mirrorquery": "self.version",
        "getpop/api-rest": "self.version",
        "getpop/application": "self.version",
        "getpop/application-wp": "self.version",
        "getpop/cache-control": "self.version",
        "getpop/component-model": "self.version",
        "getpop/component-model-configuration": "self.version",
        "getpop/configurable-schema-feedback": "self.version",
        "getpop/definitionpersistence": "self.version",
        "getpop/definitions": "self.version",
        "getpop/definitions-base36": "self.version",
        "getpop/definitions-emoji": "self.version",
        "getpop/engine": "self.version",
        "getpop/engine-wp": "self.version",
        "getpop/engine-wp-bootloader": "self.version",
        "getpop/field-query": "self.version",
        "getpop/filestore": "self.version",
        "getpop/function-fields": "self.version",
        "getpop/guzzle-helpers": "self.version",
        "getpop/hooks": "self.version",
        "getpop/hooks-wp": "self.version",
        "getpop/loosecontracts": "self.version",
        "getpop/mandatory-directives-by-configuration": "self.version",
        "getpop/migrate-api": "self.version",
        "getpop/migrate-api-graphql": "self.version",
        "getpop/migrate-component-model": "self.version",
        "getpop/migrate-component-model-configuration": "self.version",
        "getpop/migrate-engine": "self.version",
        "getpop/migrate-engine-wp": "self.version",
        "getpop/migrate-static-site-generator": "self.version",
        "getpop/modulerouting": "self.version",
        "getpop/multisite": "self.version",
        "getpop/query-parsing": "self.version",
        "getpop/resourceloader": "self.version",
        "getpop/resources": "self.version",
        "getpop/root": "self.version",
        "getpop/routing": "self.version",
        "getpop/routing-wp": "self.version",
        "getpop/site": "self.version",
        "getpop/site-wp": "self.version",
        "getpop/spa": "self.version",
        "getpop/static-site-generator": "self.version",
        "getpop/trace-tools": "self.version",
        "getpop/translation": "self.version",
        "getpop/translation-wp": "self.version",
        "graphql-api/convert-case-directives": "self.version",
        "graphql-api/graphql-api-for-wp": "self.version",
        "graphql-api/schema-feedback": "self.version",
        "graphql-by-pop/graphql-clients-for-wp": "self.version",
        "graphql-by-pop/graphql-endpoint-for-wp": "self.version",
        "graphql-by-pop/graphql-parser": "self.version",
        "graphql-by-pop/graphql-query": "self.version",
        "graphql-by-pop/graphql-request": "self.version",
        "graphql-by-pop/graphql-server": "self.version",
        "leoloso/examples-for-pop": "self.version",
        "pop-migrate-everythingelse/cssconverter": "self.version",
        "pop-migrate-everythingelse/ssr": "self.version",
        "pop-schema/basic-directives": "self.version",
        "pop-schema/block-metadata-for-wp": "self.version",
        "pop-schema/categories": "self.version",
        "pop-schema/categories-wp": "self.version",
        "pop-schema/cdn-directive": "self.version",
        "pop-schema/comment-mutations": "self.version",
        "pop-schema/comment-mutations-wp": "self.version",
        "pop-schema/commentmeta": "self.version",
        "pop-schema/commentmeta-wp": "self.version",
        "pop-schema/comments": "self.version",
        "pop-schema/comments-wp": "self.version",
        "pop-schema/convert-case-directives": "self.version",
        "pop-schema/custompost-mutations": "self.version",
        "pop-schema/custompost-mutations-wp": "self.version",
        "pop-schema/custompostmedia": "self.version",
        "pop-schema/custompostmedia-mutations": "self.version",
        "pop-schema/custompostmedia-mutations-wp": "self.version",
        "pop-schema/custompostmedia-wp": "self.version",
        "pop-schema/custompostmeta": "self.version",
        "pop-schema/custompostmeta-wp": "self.version",
        "pop-schema/customposts": "self.version",
        "pop-schema/customposts-wp": "self.version",
        "pop-schema/event-mutations": "self.version",
        "pop-schema/event-mutations-wp-em": "self.version",
        "pop-schema/events": "self.version",
        "pop-schema/events-wp-em": "self.version",
        "pop-schema/everythingelse": "self.version",
        "pop-schema/everythingelse-wp": "self.version",
        "pop-schema/generic-customposts": "self.version",
        "pop-schema/google-translate-directive": "self.version",
        "pop-schema/google-translate-directive-for-customposts": "self.version",
        "pop-schema/highlights": "self.version",
        "pop-schema/highlights-wp": "self.version",
        "pop-schema/locationposts": "self.version",
        "pop-schema/locationposts-wp": "self.version",
        "pop-schema/locations": "self.version",
        "pop-schema/locations-wp-em": "self.version",
        "pop-schema/media": "self.version",
        "pop-schema/media-wp": "self.version",
        "pop-schema/menus": "self.version",
        "pop-schema/menus-wp": "self.version",
        "pop-schema/meta": "self.version",
        "pop-schema/metaquery": "self.version",
        "pop-schema/metaquery-wp": "self.version",
        "pop-schema/migrate-categories": "self.version",
        "pop-schema/migrate-categories-wp": "self.version",
        "pop-schema/migrate-commentmeta": "self.version",
        "pop-schema/migrate-commentmeta-wp": "self.version",
        "pop-schema/migrate-comments": "self.version",
        "pop-schema/migrate-comments-wp": "self.version",
        "pop-schema/migrate-custompostmedia": "self.version",
        "pop-schema/migrate-custompostmedia-wp": "self.version",
        "pop-schema/migrate-custompostmeta": "self.version",
        "pop-schema/migrate-custompostmeta-wp": "self.version",
        "pop-schema/migrate-customposts": "self.version",
        "pop-schema/migrate-customposts-wp": "self.version",
        "pop-schema/migrate-events": "self.version",
        "pop-schema/migrate-events-wp-em": "self.version",
        "pop-schema/migrate-everythingelse": "self.version",
        "pop-schema/migrate-locations": "self.version",
        "pop-schema/migrate-locations-wp-em": "self.version",
        "pop-schema/migrate-media": "self.version",
        "pop-schema/migrate-media-wp": "self.version",
        "pop-schema/migrate-meta": "self.version",
        "pop-schema/migrate-metaquery": "self.version",
        "pop-schema/migrate-metaquery-wp": "self.version",
        "pop-schema/migrate-pages": "self.version",
        "pop-schema/migrate-pages-wp": "self.version",
        "pop-schema/migrate-post-tags": "self.version",
        "pop-schema/migrate-post-tags-wp": "self.version",
        "pop-schema/migrate-posts": "self.version",
        "pop-schema/migrate-posts-wp": "self.version",
        "pop-schema/migrate-queriedobject": "self.version",
        "pop-schema/migrate-queriedobject-wp": "self.version",
        "pop-schema/migrate-tags": "self.version",
        "pop-schema/migrate-tags-wp": "self.version",
        "pop-schema/migrate-taxonomies": "self.version",
        "pop-schema/migrate-taxonomies-wp": "self.version",
        "pop-schema/migrate-taxonomymeta": "self.version",
        "pop-schema/migrate-taxonomymeta-wp": "self.version",
        "pop-schema/migrate-taxonomyquery": "self.version",
        "pop-schema/migrate-taxonomyquery-wp": "self.version",
        "pop-schema/migrate-usermeta": "self.version",
        "pop-schema/migrate-usermeta-wp": "self.version",
        "pop-schema/migrate-users": "self.version",
        "pop-schema/migrate-users-wp": "self.version",
        "pop-schema/notifications": "self.version",
        "pop-schema/notifications-wp": "self.version",
        "pop-schema/pages": "self.version",
        "pop-schema/pages-wp": "self.version",
        "pop-schema/post-mutations": "self.version",
        "pop-schema/post-tags": "self.version",
        "pop-schema/post-tags-wp": "self.version",
        "pop-schema/posts": "self.version",
        "pop-schema/posts-wp": "self.version",
        "pop-schema/queriedobject": "self.version",
        "pop-schema/queriedobject-wp": "self.version",
        "pop-schema/schema-commons": "self.version",
        "pop-schema/stances": "self.version",
        "pop-schema/stances-wp": "self.version",
        "pop-schema/tags": "self.version",
        "pop-schema/tags-wp": "self.version",
        "pop-schema/taxonomies": "self.version",
        "pop-schema/taxonomies-wp": "self.version",
        "pop-schema/taxonomymeta": "self.version",
        "pop-schema/taxonomymeta-wp": "self.version",
        "pop-schema/taxonomyquery": "self.version",
        "pop-schema/taxonomyquery-wp": "self.version",
        "pop-schema/translate-directive": "self.version",
        "pop-schema/translate-directive-acl": "self.version",
        "pop-schema/user-roles": "self.version",
        "pop-schema/user-roles-access-control": "self.version",
        "pop-schema/user-roles-acl": "self.version",
        "pop-schema/user-roles-wp": "self.version",
        "pop-schema/user-state": "self.version",
        "pop-schema/user-state-access-control": "self.version",
        "pop-schema/user-state-mutations": "self.version",
        "pop-schema/user-state-mutations-wp": "self.version",
        "pop-schema/user-state-wp": "self.version",
        "pop-schema/usermeta": "self.version",
        "pop-schema/usermeta-wp": "self.version",
        "pop-schema/users": "self.version",
        "pop-schema/users-wp": "self.version",
        "pop-sites-wassup/comment-mutations": "self.version",
        "pop-sites-wassup/contactus-mutations": "self.version",
        "pop-sites-wassup/contactuser-mutations": "self.version",
        "pop-sites-wassup/custompost-mutations": "self.version",
        "pop-sites-wassup/custompostlink-mutations": "self.version",
        "pop-sites-wassup/event-mutations": "self.version",
        "pop-sites-wassup/eventlink-mutations": "self.version",
        "pop-sites-wassup/everythingelse-mutations": "self.version",
        "pop-sites-wassup/flag-mutations": "self.version",
        "pop-sites-wassup/form-mutations": "self.version",
        "pop-sites-wassup/gravityforms-mutations": "self.version",
        "pop-sites-wassup/highlight-mutations": "self.version",
        "pop-sites-wassup/location-mutations": "self.version",
        "pop-sites-wassup/locationpost-mutations": "self.version",
        "pop-sites-wassup/locationpostlink-mutations": "self.version",
        "pop-sites-wassup/newsletter-mutations": "self.version",
        "pop-sites-wassup/notification-mutations": "self.version",
        "pop-sites-wassup/post-mutations": "self.version",
        "pop-sites-wassup/postlink-mutations": "self.version",
        "pop-sites-wassup/share-mutations": "self.version",
        "pop-sites-wassup/socialnetwork-mutations": "self.version",
        "pop-sites-wassup/stance-mutations": "self.version",
        "pop-sites-wassup/system-mutations": "self.version",
        "pop-sites-wassup/user-state-mutations": "self.version",
        "pop-sites-wassup/volunteer-mutations": "self.version",
        "pop-sites-wassup/wassup": "self.version"
    },
    "authors": [
        {
            "name": "Leonardo Losoviz",
            "email": "leo@getpop.org",
            "homepage": "https://getpop.org"
        }
    ],
    "description": "Monorepo for all the PoP packages",
    "license": "GPL-2.0-or-later",
    "config": {
        "sort-packages": true
    },
    "repositories": [
        {
            "type": "composer",
            "url": "https://wpackagist.org"
        },
        {
            "type": "vcs",
            "url": "https://github.com/leoloso/wp-muplugin-loader.git"
        },
        {
            "type": "vcs",
            "url": "https://github.com/mcaskill/composer-merge-plugin.git"
        }
    ],
    "scripts": {
        "test": "phpunit",
        "check-style": "phpcs -n src $(monorepo-builder source-packages --subfolder=src --subfolder=tests)",
        "fix-style": "phpcbf -n src $(monorepo-builder source-packages --subfolder=src --subfolder=tests)",
        "analyse": "ci/phpstan.sh \\". $(monorepo-builder source-packages --skip-unmigrated)\\"",
        "preview-src-downgrade": "rector process $(monorepo-builder source-packages --subfolder=src) --config=rector-downgrade-code.php --ansi --dry-run || true",
        "preview-vendor-downgrade": "layers/Engine/packages/root/ci/downgrade_code.sh 7.1 rector-downgrade-code.php --dry-run || true",
        "preview-code-downgrade": [
            "@preview-src-downgrade",
            "@preview-vendor-downgrade"
        ],
        "build-server": [
            "lando init --source remote --remote-url https://wordpress.org/latest.tar.gz --recipe wordpress --webroot wordpress --name graphql-api-dev",
            "@start-server"
        ],
        "start-server": [
            "cd layers/GraphQLAPIForWP/plugins/graphql-api-for-wp && composer install",
            "lando start"
        ],
        "rebuild-server": "lando rebuild -y",
        "merge-monorepo": "monorepo-builder merge --ansi",
        "propagate-monorepo": "monorepo-builder propagate --ansi",
        "validate-monorepo": "monorepo-builder validate --ansi",
        "release": "monorepo-builder release patch --ansi"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}');

        self::assertTrue($manipulator->addSubNode('config', 'platform-check', false));
        self::assertEquals('{
    "name": "leoloso/pop",
    "require": {
        "php": "^7.4|^8.0",
        "ext-mbstring": "*",
        "brain/cortex": "~1.0.0",
        "composer/installers": "~1.0",
        "composer/semver": "^1.5",
        "erusev/parsedown": "^1.7",
        "guzzlehttp/guzzle": "~6.3",
        "jrfnl/php-cast-to-type": "^2.0",
        "league/pipeline": "^1.0",
        "lkwdwrd/wp-muplugin-loader": "dev-feature-composer-v2",
        "obsidian/polyfill-hrtime": "^0.1",
        "psr/cache": "^1.0",
        "symfony/cache": "^5.1",
        "symfony/config": "^5.1",
        "symfony/dependency-injection": "^5.1",
        "symfony/dotenv": "^5.1",
        "symfony/expression-language": "^5.1",
        "symfony/polyfill-php72": "^1.18",
        "symfony/polyfill-php73": "^1.18",
        "symfony/polyfill-php74": "^1.18",
        "symfony/polyfill-php80": "^1.18",
        "symfony/property-access": "^5.1",
        "symfony/yaml": "^5.1"
    },
    "require-dev": {
        "johnpbloch/wordpress": ">=5.5",
        "phpstan/phpstan": "^0.12",
        "phpunit/phpunit": ">=9.3",
        "rector/rector": "^0.9",
        "squizlabs/php_codesniffer": "^3.0",
        "symfony/var-dumper": "^5.1",
        "symplify/monorepo-builder": "^9.0",
        "szepeviktor/phpstan-wordpress": "^0.6.2"
    },
    "autoload": {
        "psr-4": {
            "GraphQLAPI\\\\ConvertCaseDirectives\\\\": "layers/GraphQLAPIForWP/plugins/convert-case-directives/src",
            "GraphQLAPI\\\\GraphQLAPI\\\\": "layers/GraphQLAPIForWP/plugins/graphql-api-for-wp/src",
            "GraphQLAPI\\\\SchemaFeedback\\\\": "layers/GraphQLAPIForWP/plugins/schema-feedback/src",
            "GraphQLByPoP\\\\GraphQLClientsForWP\\\\": "layers/GraphQLByPoP/packages/graphql-clients-for-wp/src",
            "GraphQLByPoP\\\\GraphQLEndpointForWP\\\\": "layers/GraphQLByPoP/packages/graphql-endpoint-for-wp/src",
            "GraphQLByPoP\\\\GraphQLParser\\\\": "layers/GraphQLByPoP/packages/graphql-parser/src",
            "GraphQLByPoP\\\\GraphQLQuery\\\\": "layers/GraphQLByPoP/packages/graphql-query/src",
            "GraphQLByPoP\\\\GraphQLRequest\\\\": "layers/GraphQLByPoP/packages/graphql-request/src",
            "GraphQLByPoP\\\\GraphQLServer\\\\": "layers/GraphQLByPoP/packages/graphql-server/src",
            "Leoloso\\\\ExamplesForPoP\\\\": "layers/Misc/packages/examples-for-pop/src",
            "PoPSchema\\\\BasicDirectives\\\\": "layers/Schema/packages/basic-directives/src",
            "PoPSchema\\\\BlockMetadataWP\\\\": "layers/Schema/packages/block-metadata-for-wp/src",
            "PoPSchema\\\\CDNDirective\\\\": "layers/Schema/packages/cdn-directive/src",
            "PoPSchema\\\\CategoriesWP\\\\": "layers/Schema/packages/categories-wp/src",
            "PoPSchema\\\\Categories\\\\": "layers/Schema/packages/categories/src",
            "PoPSchema\\\\CommentMetaWP\\\\": "layers/Schema/packages/commentmeta-wp/src",
            "PoPSchema\\\\CommentMeta\\\\": "layers/Schema/packages/commentmeta/src",
            "PoPSchema\\\\CommentMutationsWP\\\\": "layers/Schema/packages/comment-mutations-wp/src",
            "PoPSchema\\\\CommentMutations\\\\": "layers/Schema/packages/comment-mutations/src",
            "PoPSchema\\\\CommentsWP\\\\": "layers/Schema/packages/comments-wp/src",
            "PoPSchema\\\\Comments\\\\": "layers/Schema/packages/comments/src",
            "PoPSchema\\\\ConvertCaseDirectives\\\\": "layers/Schema/packages/convert-case-directives/src",
            "PoPSchema\\\\CustomPostMediaMutationsWP\\\\": "layers/Schema/packages/custompostmedia-mutations-wp/src",
            "PoPSchema\\\\CustomPostMediaMutations\\\\": "layers/Schema/packages/custompostmedia-mutations/src",
            "PoPSchema\\\\CustomPostMediaWP\\\\": "layers/Schema/packages/custompostmedia-wp/src",
            "PoPSchema\\\\CustomPostMedia\\\\": "layers/Schema/packages/custompostmedia/src",
            "PoPSchema\\\\CustomPostMetaWP\\\\": "layers/Schema/packages/custompostmeta-wp/src",
            "PoPSchema\\\\CustomPostMeta\\\\": "layers/Schema/packages/custompostmeta/src",
            "PoPSchema\\\\CustomPostMutationsWP\\\\": "layers/Schema/packages/custompost-mutations-wp/src",
            "PoPSchema\\\\CustomPostMutations\\\\": "layers/Schema/packages/custompost-mutations/src",
            "PoPSchema\\\\CustomPostsWP\\\\": "layers/Schema/packages/customposts-wp/src",
            "PoPSchema\\\\CustomPosts\\\\": "layers/Schema/packages/customposts/src",
            "PoPSchema\\\\EventMutationsWPEM\\\\": "layers/Schema/packages/event-mutations-wp-em/src",
            "PoPSchema\\\\EventMutations\\\\": "layers/Schema/packages/event-mutations/src",
            "PoPSchema\\\\EventsWPEM\\\\": "layers/Schema/packages/events-wp-em/src",
            "PoPSchema\\\\Events\\\\": "layers/Schema/packages/events/src",
            "PoPSchema\\\\EverythingElseWP\\\\": "layers/Schema/packages/everythingelse-wp/src",
            "PoPSchema\\\\EverythingElse\\\\": "layers/Schema/packages/everythingelse/src",
            "PoPSchema\\\\GenericCustomPosts\\\\": "layers/Schema/packages/generic-customposts/src",
            "PoPSchema\\\\GoogleTranslateDirectiveForCustomPosts\\\\": "layers/Schema/packages/google-translate-directive-for-customposts/src",
            "PoPSchema\\\\GoogleTranslateDirective\\\\": "layers/Schema/packages/google-translate-directive/src",
            "PoPSchema\\\\HighlightsWP\\\\": "layers/Schema/packages/highlights-wp/src",
            "PoPSchema\\\\Highlights\\\\": "layers/Schema/packages/highlights/src",
            "PoPSchema\\\\LocationPostsWP\\\\": "layers/Schema/packages/locationposts-wp/src",
            "PoPSchema\\\\LocationPosts\\\\": "layers/Schema/packages/locationposts/src",
            "PoPSchema\\\\LocationsWPEM\\\\": "layers/Schema/packages/locations-wp-em/src",
            "PoPSchema\\\\Locations\\\\": "layers/Schema/packages/locations/src",
            "PoPSchema\\\\MediaWP\\\\": "layers/Schema/packages/media-wp/src",
            "PoPSchema\\\\Media\\\\": "layers/Schema/packages/media/src",
            "PoPSchema\\\\MenusWP\\\\": "layers/Schema/packages/menus-wp/src",
            "PoPSchema\\\\Menus\\\\": "layers/Schema/packages/menus/src",
            "PoPSchema\\\\MetaQueryWP\\\\": "layers/Schema/packages/metaquery-wp/src",
            "PoPSchema\\\\MetaQuery\\\\": "layers/Schema/packages/metaquery/src",
            "PoPSchema\\\\Meta\\\\": "layers/Schema/packages/meta/src",
            "PoPSchema\\\\NotificationsWP\\\\": "layers/Schema/packages/notifications-wp/src",
            "PoPSchema\\\\Notifications\\\\": "layers/Schema/packages/notifications/src",
            "PoPSchema\\\\PagesWP\\\\": "layers/Schema/packages/pages-wp/src",
            "PoPSchema\\\\Pages\\\\": "layers/Schema/packages/pages/src",
            "PoPSchema\\\\PostMutations\\\\": "layers/Schema/packages/post-mutations/src",
            "PoPSchema\\\\PostTagsWP\\\\": "layers/Schema/packages/post-tags-wp/src",
            "PoPSchema\\\\PostTags\\\\": "layers/Schema/packages/post-tags/src",
            "PoPSchema\\\\PostsWP\\\\": "layers/Schema/packages/posts-wp/src",
            "PoPSchema\\\\Posts\\\\": "layers/Schema/packages/posts/src",
            "PoPSchema\\\\QueriedObjectWP\\\\": "layers/Schema/packages/queriedobject-wp/src",
            "PoPSchema\\\\QueriedObject\\\\": "layers/Schema/packages/queriedobject/src",
            "PoPSchema\\\\SchemaCommons\\\\": "layers/Schema/packages/schema-commons/src",
            "PoPSchema\\\\StancesWP\\\\": "layers/Schema/packages/stances-wp/src",
            "PoPSchema\\\\Stances\\\\": "layers/Schema/packages/stances/src",
            "PoPSchema\\\\TagsWP\\\\": "layers/Schema/packages/tags-wp/src",
            "PoPSchema\\\\Tags\\\\": "layers/Schema/packages/tags/src",
            "PoPSchema\\\\TaxonomiesWP\\\\": "layers/Schema/packages/taxonomies-wp/src",
            "PoPSchema\\\\Taxonomies\\\\": "layers/Schema/packages/taxonomies/src",
            "PoPSchema\\\\TaxonomyMetaWP\\\\": "layers/Schema/packages/taxonomymeta-wp/src",
            "PoPSchema\\\\TaxonomyMeta\\\\": "layers/Schema/packages/taxonomymeta/src",
            "PoPSchema\\\\TaxonomyQueryWP\\\\": "layers/Schema/packages/taxonomyquery-wp/src",
            "PoPSchema\\\\TaxonomyQuery\\\\": "layers/Schema/packages/taxonomyquery/src",
            "PoPSchema\\\\TranslateDirectiveACL\\\\": "layers/Schema/packages/translate-directive-acl/src",
            "PoPSchema\\\\TranslateDirective\\\\": "layers/Schema/packages/translate-directive/src",
            "PoPSchema\\\\UserMetaWP\\\\": "layers/Schema/packages/usermeta-wp/src",
            "PoPSchema\\\\UserMeta\\\\": "layers/Schema/packages/usermeta/src",
            "PoPSchema\\\\UserRolesACL\\\\": "layers/Schema/packages/user-roles-acl/src",
            "PoPSchema\\\\UserRolesAccessControl\\\\": "layers/Schema/packages/user-roles-access-control/src",
            "PoPSchema\\\\UserRolesWP\\\\": "layers/Schema/packages/user-roles-wp/src",
            "PoPSchema\\\\UserRoles\\\\": "layers/Schema/packages/user-roles/src",
            "PoPSchema\\\\UserStateAccessControl\\\\": "layers/Schema/packages/user-state-access-control/src",
            "PoPSchema\\\\UserStateMutationsWP\\\\": "layers/Schema/packages/user-state-mutations-wp/src",
            "PoPSchema\\\\UserStateMutations\\\\": "layers/Schema/packages/user-state-mutations/src",
            "PoPSchema\\\\UserStateWP\\\\": "layers/Schema/packages/user-state-wp/src",
            "PoPSchema\\\\UserState\\\\": "layers/Schema/packages/user-state/src",
            "PoPSchema\\\\UsersWP\\\\": "layers/Schema/packages/users-wp/src",
            "PoPSchema\\\\Users\\\\": "layers/Schema/packages/users/src",
            "PoPSitesWassup\\\\CommentMutations\\\\": "layers/Wassup/packages/comment-mutations/src",
            "PoPSitesWassup\\\\ContactUsMutations\\\\": "layers/Wassup/packages/contactus-mutations/src",
            "PoPSitesWassup\\\\ContactUserMutations\\\\": "layers/Wassup/packages/contactuser-mutations/src",
            "PoPSitesWassup\\\\CustomPostLinkMutations\\\\": "layers/Wassup/packages/custompostlink-mutations/src",
            "PoPSitesWassup\\\\CustomPostMutations\\\\": "layers/Wassup/packages/custompost-mutations/src",
            "PoPSitesWassup\\\\EventLinkMutations\\\\": "layers/Wassup/packages/eventlink-mutations/src",
            "PoPSitesWassup\\\\EventMutations\\\\": "layers/Wassup/packages/event-mutations/src",
            "PoPSitesWassup\\\\EverythingElseMutations\\\\": "layers/Wassup/packages/everythingelse-mutations/src",
            "PoPSitesWassup\\\\FlagMutations\\\\": "layers/Wassup/packages/flag-mutations/src",
            "PoPSitesWassup\\\\FormMutations\\\\": "layers/Wassup/packages/form-mutations/src",
            "PoPSitesWassup\\\\GravityFormsMutations\\\\": "layers/Wassup/packages/gravityforms-mutations/src",
            "PoPSitesWassup\\\\HighlightMutations\\\\": "layers/Wassup/packages/highlight-mutations/src",
            "PoPSitesWassup\\\\LocationMutations\\\\": "layers/Wassup/packages/location-mutations/src",
            "PoPSitesWassup\\\\LocationPostLinkMutations\\\\": "layers/Wassup/packages/locationpostlink-mutations/src",
            "PoPSitesWassup\\\\LocationPostMutations\\\\": "layers/Wassup/packages/locationpost-mutations/src",
            "PoPSitesWassup\\\\NewsletterMutations\\\\": "layers/Wassup/packages/newsletter-mutations/src",
            "PoPSitesWassup\\\\NotificationMutations\\\\": "layers/Wassup/packages/notification-mutations/src",
            "PoPSitesWassup\\\\PostLinkMutations\\\\": "layers/Wassup/packages/postlink-mutations/src",
            "PoPSitesWassup\\\\PostMutations\\\\": "layers/Wassup/packages/post-mutations/src",
            "PoPSitesWassup\\\\ShareMutations\\\\": "layers/Wassup/packages/share-mutations/src",
            "PoPSitesWassup\\\\SocialNetworkMutations\\\\": "layers/Wassup/packages/socialnetwork-mutations/src",
            "PoPSitesWassup\\\\StanceMutations\\\\": "layers/Wassup/packages/stance-mutations/src",
            "PoPSitesWassup\\\\SystemMutations\\\\": "layers/Wassup/packages/system-mutations/src",
            "PoPSitesWassup\\\\UserStateMutations\\\\": "layers/Wassup/packages/user-state-mutations/src",
            "PoPSitesWassup\\\\VolunteerMutations\\\\": "layers/Wassup/packages/volunteer-mutations/src",
            "PoPSitesWassup\\\\Wassup\\\\": "layers/Wassup/packages/wassup/src",
            "PoP\\\\APIClients\\\\": "layers/API/packages/api-clients/src",
            "PoP\\\\APIEndpointsForWP\\\\": "layers/API/packages/api-endpoints-for-wp/src",
            "PoP\\\\APIEndpoints\\\\": "layers/API/packages/api-endpoints/src",
            "PoP\\\\APIMirrorQuery\\\\": "layers/API/packages/api-mirrorquery/src",
            "PoP\\\\API\\\\": "layers/API/packages/api/src",
            "PoP\\\\AccessControl\\\\": "layers/Engine/packages/access-control/src",
            "PoP\\\\ApplicationWP\\\\": "layers/SiteBuilder/packages/application-wp/src",
            "PoP\\\\Application\\\\": "layers/SiteBuilder/packages/application/src",
            "PoP\\\\Base36Definitions\\\\": "layers/SiteBuilder/packages/definitions-base36/src",
            "PoP\\\\CacheControl\\\\": "layers/Engine/packages/cache-control/src",
            "PoP\\\\ComponentModel\\\\": "layers/Engine/packages/component-model/src",
            "PoP\\\\ConfigurableSchemaFeedback\\\\": "layers/Engine/packages/configurable-schema-feedback/src",
            "PoP\\\\ConfigurationComponentModel\\\\": "layers/SiteBuilder/packages/component-model-configuration/src",
            "PoP\\\\DefinitionPersistence\\\\": "layers/SiteBuilder/packages/definitionpersistence/src",
            "PoP\\\\Definitions\\\\": "layers/Engine/packages/definitions/src",
            "PoP\\\\EmojiDefinitions\\\\": "layers/SiteBuilder/packages/definitions-emoji/src",
            "PoP\\\\EngineWP\\\\": "layers/Engine/packages/engine-wp/src",
            "PoP\\\\Engine\\\\": "layers/Engine/packages/engine/src",
            "PoP\\\\FieldQuery\\\\": "layers/Engine/packages/field-query/src",
            "PoP\\\\FileStore\\\\": "layers/Engine/packages/filestore/src",
            "PoP\\\\FunctionFields\\\\": "layers/Engine/packages/function-fields/src",
            "PoP\\\\GraphQLAPI\\\\": "layers/API/packages/api-graphql/src",
            "PoP\\\\GuzzleHelpers\\\\": "layers/Engine/packages/guzzle-helpers/src",
            "PoP\\\\HooksWP\\\\": "layers/Engine/packages/hooks-wp/src",
            "PoP\\\\Hooks\\\\": "layers/Engine/packages/hooks/src",
            "PoP\\\\LooseContracts\\\\": "layers/Engine/packages/loosecontracts/src",
            "PoP\\\\MandatoryDirectivesByConfiguration\\\\": "layers/Engine/packages/mandatory-directives-by-configuration/src",
            "PoP\\\\ModuleRouting\\\\": "layers/Engine/packages/modulerouting/src",
            "PoP\\\\Multisite\\\\": "layers/SiteBuilder/packages/multisite/src",
            "PoP\\\\PoP\\\\": "src",
            "PoP\\\\QueryParsing\\\\": "layers/Engine/packages/query-parsing/src",
            "PoP\\\\RESTAPI\\\\": "layers/API/packages/api-rest/src",
            "PoP\\\\ResourceLoader\\\\": "layers/SiteBuilder/packages/resourceloader/src",
            "PoP\\\\Resources\\\\": "layers/SiteBuilder/packages/resources/src",
            "PoP\\\\Root\\\\": "layers/Engine/packages/root/src",
            "PoP\\\\RoutingWP\\\\": "layers/Engine/packages/routing-wp/src",
            "PoP\\\\Routing\\\\": "layers/Engine/packages/routing/src",
            "PoP\\\\SPA\\\\": "layers/SiteBuilder/packages/spa/src",
            "PoP\\\\SSG\\\\": "layers/SiteBuilder/packages/static-site-generator/src",
            "PoP\\\\SiteWP\\\\": "layers/SiteBuilder/packages/site-wp/src",
            "PoP\\\\Site\\\\": "layers/SiteBuilder/packages/site/src",
            "PoP\\\\TraceTools\\\\": "layers/Engine/packages/trace-tools/src",
            "PoP\\\\TranslationWP\\\\": "layers/Engine/packages/translation-wp/src",
            "PoP\\\\Translation\\\\": "layers/Engine/packages/translation/src"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "GraphQLAPI\\\\ConvertCaseDirectives\\\\": "layers/GraphQLAPIForWP/plugins/convert-case-directives/tests",
            "GraphQLAPI\\\\GraphQLAPI\\\\": "layers/GraphQLAPIForWP/plugins/graphql-api-for-wp/tests",
            "GraphQLAPI\\\\SchemaFeedback\\\\": "layers/GraphQLAPIForWP/plugins/schema-feedback/tests",
            "GraphQLByPoP\\\\GraphQLClientsForWP\\\\": "layers/GraphQLByPoP/packages/graphql-clients-for-wp/tests",
            "GraphQLByPoP\\\\GraphQLEndpointForWP\\\\": "layers/GraphQLByPoP/packages/graphql-endpoint-for-wp/tests",
            "GraphQLByPoP\\\\GraphQLParser\\\\": "layers/GraphQLByPoP/packages/graphql-parser/tests",
            "GraphQLByPoP\\\\GraphQLQuery\\\\": "layers/GraphQLByPoP/packages/graphql-query/tests",
            "GraphQLByPoP\\\\GraphQLRequest\\\\": "layers/GraphQLByPoP/packages/graphql-request/tests",
            "GraphQLByPoP\\\\GraphQLServer\\\\": "layers/GraphQLByPoP/packages/graphql-server/tests",
            "Leoloso\\\\ExamplesForPoP\\\\": "layers/Misc/packages/examples-for-pop/tests",
            "PoPSchema\\\\BasicDirectives\\\\": "layers/Schema/packages/basic-directives/tests",
            "PoPSchema\\\\BlockMetadataWP\\\\": "layers/Schema/packages/block-metadata-for-wp/tests",
            "PoPSchema\\\\CDNDirective\\\\": "layers/Schema/packages/cdn-directive/tests",
            "PoPSchema\\\\CategoriesWP\\\\": "layers/Schema/packages/categories-wp/tests",
            "PoPSchema\\\\Categories\\\\": "layers/Schema/packages/categories/tests",
            "PoPSchema\\\\CommentMetaWP\\\\": "layers/Schema/packages/commentmeta-wp/tests",
            "PoPSchema\\\\CommentMeta\\\\": "layers/Schema/packages/commentmeta/tests",
            "PoPSchema\\\\CommentMutationsWP\\\\": "layers/Schema/packages/comment-mutations-wp/tests",
            "PoPSchema\\\\CommentMutations\\\\": "layers/Schema/packages/comment-mutations/tests",
            "PoPSchema\\\\CommentsWP\\\\": "layers/Schema/packages/comments-wp/tests",
            "PoPSchema\\\\Comments\\\\": "layers/Schema/packages/comments/tests",
            "PoPSchema\\\\ConvertCaseDirectives\\\\": "layers/Schema/packages/convert-case-directives/tests",
            "PoPSchema\\\\CustomPostMediaMutationsWP\\\\": "layers/Schema/packages/custompostmedia-mutations-wp/tests",
            "PoPSchema\\\\CustomPostMediaMutations\\\\": "layers/Schema/packages/custompostmedia-mutations/tests",
            "PoPSchema\\\\CustomPostMediaWP\\\\": "layers/Schema/packages/custompostmedia-wp/tests",
            "PoPSchema\\\\CustomPostMedia\\\\": "layers/Schema/packages/custompostmedia/tests",
            "PoPSchema\\\\CustomPostMetaWP\\\\": "layers/Schema/packages/custompostmeta-wp/tests",
            "PoPSchema\\\\CustomPostMeta\\\\": "layers/Schema/packages/custompostmeta/tests",
            "PoPSchema\\\\CustomPostMutationsWP\\\\": "layers/Schema/packages/custompost-mutations-wp/tests",
            "PoPSchema\\\\CustomPostMutations\\\\": "layers/Schema/packages/custompost-mutations/tests",
            "PoPSchema\\\\CustomPostsWP\\\\": "layers/Schema/packages/customposts-wp/tests",
            "PoPSchema\\\\CustomPosts\\\\": "layers/Schema/packages/customposts/tests",
            "PoPSchema\\\\EventMutationsWPEM\\\\": "layers/Schema/packages/event-mutations-wp-em/tests",
            "PoPSchema\\\\EventMutations\\\\": "layers/Schema/packages/event-mutations/tests",
            "PoPSchema\\\\EventsWPEM\\\\": "layers/Schema/packages/events-wp-em/tests",
            "PoPSchema\\\\Events\\\\": "layers/Schema/packages/events/tests",
            "PoPSchema\\\\EverythingElseWP\\\\": "layers/Schema/packages/everythingelse-wp/tests",
            "PoPSchema\\\\EverythingElse\\\\": "layers/Schema/packages/everythingelse/tests",
            "PoPSchema\\\\GenericCustomPosts\\\\": "layers/Schema/packages/generic-customposts/tests",
            "PoPSchema\\\\GoogleTranslateDirectiveForCustomPosts\\\\": "layers/Schema/packages/google-translate-directive-for-customposts/tests",
            "PoPSchema\\\\GoogleTranslateDirective\\\\": "layers/Schema/packages/google-translate-directive/tests",
            "PoPSchema\\\\HighlightsWP\\\\": "layers/Schema/packages/highlights-wp/tests",
            "PoPSchema\\\\Highlights\\\\": "layers/Schema/packages/highlights/tests",
            "PoPSchema\\\\LocationPostsWP\\\\": "layers/Schema/packages/locationposts-wp/tests",
            "PoPSchema\\\\LocationPosts\\\\": "layers/Schema/packages/locationposts/tests",
            "PoPSchema\\\\LocationsWPEM\\\\": "layers/Schema/packages/locations-wp-em/tests",
            "PoPSchema\\\\Locations\\\\": "layers/Schema/packages/locations/tests",
            "PoPSchema\\\\MediaWP\\\\": "layers/Schema/packages/media-wp/tests",
            "PoPSchema\\\\Media\\\\": "layers/Schema/packages/media/tests",
            "PoPSchema\\\\MenusWP\\\\": "layers/Schema/packages/menus-wp/tests",
            "PoPSchema\\\\Menus\\\\": "layers/Schema/packages/menus/tests",
            "PoPSchema\\\\MetaQueryWP\\\\": "layers/Schema/packages/metaquery-wp/tests",
            "PoPSchema\\\\MetaQuery\\\\": "layers/Schema/packages/metaquery/tests",
            "PoPSchema\\\\Meta\\\\": "layers/Schema/packages/meta/tests",
            "PoPSchema\\\\NotificationsWP\\\\": "layers/Schema/packages/notifications-wp/tests",
            "PoPSchema\\\\Notifications\\\\": "layers/Schema/packages/notifications/tests",
            "PoPSchema\\\\PagesWP\\\\": "layers/Schema/packages/pages-wp/tests",
            "PoPSchema\\\\Pages\\\\": "layers/Schema/packages/pages/tests",
            "PoPSchema\\\\PostMutations\\\\": "layers/Schema/packages/post-mutations/tests",
            "PoPSchema\\\\PostTagsWP\\\\": "layers/Schema/packages/post-tags-wp/tests",
            "PoPSchema\\\\PostTags\\\\": "layers/Schema/packages/post-tags/tests",
            "PoPSchema\\\\PostsWP\\\\": "layers/Schema/packages/posts-wp/tests",
            "PoPSchema\\\\Posts\\\\": "layers/Schema/packages/posts/tests",
            "PoPSchema\\\\QueriedObjectWP\\\\": "layers/Schema/packages/queriedobject-wp/tests",
            "PoPSchema\\\\QueriedObject\\\\": "layers/Schema/packages/queriedobject/tests",
            "PoPSchema\\\\SchemaCommons\\\\": "layers/Schema/packages/schema-commons/tests",
            "PoPSchema\\\\StancesWP\\\\": "layers/Schema/packages/stances-wp/tests",
            "PoPSchema\\\\Stances\\\\": "layers/Schema/packages/stances/tests",
            "PoPSchema\\\\TagsWP\\\\": "layers/Schema/packages/tags-wp/tests",
            "PoPSchema\\\\Tags\\\\": "layers/Schema/packages/tags/tests",
            "PoPSchema\\\\TaxonomiesWP\\\\": "layers/Schema/packages/taxonomies-wp/tests",
            "PoPSchema\\\\Taxonomies\\\\": "layers/Schema/packages/taxonomies/tests",
            "PoPSchema\\\\TaxonomyMetaWP\\\\": "layers/Schema/packages/taxonomymeta-wp/tests",
            "PoPSchema\\\\TaxonomyMeta\\\\": "layers/Schema/packages/taxonomymeta/tests",
            "PoPSchema\\\\TaxonomyQueryWP\\\\": "layers/Schema/packages/taxonomyquery-wp/tests",
            "PoPSchema\\\\TaxonomyQuery\\\\": "layers/Schema/packages/taxonomyquery/tests",
            "PoPSchema\\\\TranslateDirectiveACL\\\\": "layers/Schema/packages/translate-directive-acl/tests",
            "PoPSchema\\\\TranslateDirective\\\\": "layers/Schema/packages/translate-directive/tests",
            "PoPSchema\\\\UserMetaWP\\\\": "layers/Schema/packages/usermeta-wp/tests",
            "PoPSchema\\\\UserMeta\\\\": "layers/Schema/packages/usermeta/tests",
            "PoPSchema\\\\UserRolesACL\\\\": "layers/Schema/packages/user-roles-acl/tests",
            "PoPSchema\\\\UserRolesAccessControl\\\\": "layers/Schema/packages/user-roles-access-control/tests",
            "PoPSchema\\\\UserRolesWP\\\\": "layers/Schema/packages/user-roles-wp/tests",
            "PoPSchema\\\\UserRoles\\\\": "layers/Schema/packages/user-roles/tests",
            "PoPSchema\\\\UserStateAccessControl\\\\": "layers/Schema/packages/user-state-access-control/tests",
            "PoPSchema\\\\UserStateMutationsWP\\\\": "layers/Schema/packages/user-state-mutations-wp/tests",
            "PoPSchema\\\\UserStateMutations\\\\": "layers/Schema/packages/user-state-mutations/tests",
            "PoPSchema\\\\UserStateWP\\\\": "layers/Schema/packages/user-state-wp/tests",
            "PoPSchema\\\\UserState\\\\": "layers/Schema/packages/user-state/tests",
            "PoPSchema\\\\UsersWP\\\\": "layers/Schema/packages/users-wp/tests",
            "PoPSchema\\\\Users\\\\": "layers/Schema/packages/users/tests",
            "PoPSitesWassup\\\\CommentMutations\\\\": "layers/Wassup/packages/comment-mutations/tests",
            "PoPSitesWassup\\\\ContactUsMutations\\\\": "layers/Wassup/packages/contactus-mutations/tests",
            "PoPSitesWassup\\\\ContactUserMutations\\\\": "layers/Wassup/packages/contactuser-mutations/tests",
            "PoPSitesWassup\\\\CustomPostLinkMutations\\\\": "layers/Wassup/packages/custompostlink-mutations/tests",
            "PoPSitesWassup\\\\CustomPostMutations\\\\": "layers/Wassup/packages/custompost-mutations/tests",
            "PoPSitesWassup\\\\EventLinkMutations\\\\": "layers/Wassup/packages/eventlink-mutations/tests",
            "PoPSitesWassup\\\\EventMutations\\\\": "layers/Wassup/packages/event-mutations/tests",
            "PoPSitesWassup\\\\EverythingElseMutations\\\\": "layers/Wassup/packages/everythingelse-mutations/tests",
            "PoPSitesWassup\\\\FlagMutations\\\\": "layers/Wassup/packages/flag-mutations/tests",
            "PoPSitesWassup\\\\FormMutations\\\\": "layers/Wassup/packages/form-mutations/tests",
            "PoPSitesWassup\\\\GravityFormsMutations\\\\": "layers/Wassup/packages/gravityforms-mutations/tests",
            "PoPSitesWassup\\\\HighlightMutations\\\\": "layers/Wassup/packages/highlight-mutations/tests",
            "PoPSitesWassup\\\\LocationMutations\\\\": "layers/Wassup/packages/location-mutations/tests",
            "PoPSitesWassup\\\\LocationPostLinkMutations\\\\": "layers/Wassup/packages/locationpostlink-mutations/tests",
            "PoPSitesWassup\\\\LocationPostMutations\\\\": "layers/Wassup/packages/locationpost-mutations/tests",
            "PoPSitesWassup\\\\NewsletterMutations\\\\": "layers/Wassup/packages/newsletter-mutations/tests",
            "PoPSitesWassup\\\\NotificationMutations\\\\": "layers/Wassup/packages/notification-mutations/tests",
            "PoPSitesWassup\\\\PostLinkMutations\\\\": "layers/Wassup/packages/postlink-mutations/tests",
            "PoPSitesWassup\\\\PostMutations\\\\": "layers/Wassup/packages/post-mutations/tests",
            "PoPSitesWassup\\\\ShareMutations\\\\": "layers/Wassup/packages/share-mutations/tests",
            "PoPSitesWassup\\\\SocialNetworkMutations\\\\": "layers/Wassup/packages/socialnetwork-mutations/tests",
            "PoPSitesWassup\\\\StanceMutations\\\\": "layers/Wassup/packages/stance-mutations/tests",
            "PoPSitesWassup\\\\SystemMutations\\\\": "layers/Wassup/packages/system-mutations/tests",
            "PoPSitesWassup\\\\UserStateMutations\\\\": "layers/Wassup/packages/user-state-mutations/tests",
            "PoPSitesWassup\\\\VolunteerMutations\\\\": "layers/Wassup/packages/volunteer-mutations/tests",
            "PoPSitesWassup\\\\Wassup\\\\": "layers/Wassup/packages/wassup/tests",
            "PoP\\\\APIClients\\\\": "layers/API/packages/api-clients/tests",
            "PoP\\\\APIEndpointsForWP\\\\": "layers/API/packages/api-endpoints-for-wp/tests",
            "PoP\\\\APIEndpoints\\\\": "layers/API/packages/api-endpoints/tests",
            "PoP\\\\APIMirrorQuery\\\\": "layers/API/packages/api-mirrorquery/tests",
            "PoP\\\\API\\\\": "layers/API/packages/api/tests",
            "PoP\\\\AccessControl\\\\": "layers/Engine/packages/access-control/tests",
            "PoP\\\\ApplicationWP\\\\": "layers/SiteBuilder/packages/application-wp/tests",
            "PoP\\\\Application\\\\": "layers/SiteBuilder/packages/application/tests",
            "PoP\\\\Base36Definitions\\\\": "layers/SiteBuilder/packages/definitions-base36/tests",
            "PoP\\\\CacheControl\\\\": "layers/Engine/packages/cache-control/tests",
            "PoP\\\\ComponentModel\\\\": "layers/Engine/packages/component-model/tests",
            "PoP\\\\ConfigurableSchemaFeedback\\\\": "layers/Engine/packages/configurable-schema-feedback/tests",
            "PoP\\\\ConfigurationComponentModel\\\\": "layers/SiteBuilder/packages/component-model-configuration/tests",
            "PoP\\\\DefinitionPersistence\\\\": "layers/SiteBuilder/packages/definitionpersistence/tests",
            "PoP\\\\Definitions\\\\": "layers/Engine/packages/definitions/tests",
            "PoP\\\\EmojiDefinitions\\\\": "layers/SiteBuilder/packages/definitions-emoji/tests",
            "PoP\\\\EngineWP\\\\": "layers/Engine/packages/engine-wp/tests",
            "PoP\\\\Engine\\\\": "layers/Engine/packages/engine/tests",
            "PoP\\\\FieldQuery\\\\": "layers/Engine/packages/field-query/tests",
            "PoP\\\\FileStore\\\\": "layers/Engine/packages/filestore/tests",
            "PoP\\\\FunctionFields\\\\": "layers/Engine/packages/function-fields/tests",
            "PoP\\\\GraphQLAPI\\\\": "layers/API/packages/api-graphql/tests",
            "PoP\\\\GuzzleHelpers\\\\": "layers/Engine/packages/guzzle-helpers/tests",
            "PoP\\\\HooksWP\\\\": "layers/Engine/packages/hooks-wp/tests",
            "PoP\\\\Hooks\\\\": "layers/Engine/packages/hooks/tests",
            "PoP\\\\LooseContracts\\\\": "layers/Engine/packages/loosecontracts/tests",
            "PoP\\\\MandatoryDirectivesByConfiguration\\\\": "layers/Engine/packages/mandatory-directives-by-configuration/tests",
            "PoP\\\\ModuleRouting\\\\": "layers/Engine/packages/modulerouting/tests",
            "PoP\\\\Multisite\\\\": "layers/SiteBuilder/packages/multisite/tests",
            "PoP\\\\QueryParsing\\\\": "layers/Engine/packages/query-parsing/tests",
            "PoP\\\\RESTAPI\\\\": "layers/API/packages/api-rest/tests",
            "PoP\\\\ResourceLoader\\\\": "layers/SiteBuilder/packages/resourceloader/tests",
            "PoP\\\\Resources\\\\": "layers/SiteBuilder/packages/resources/tests",
            "PoP\\\\Root\\\\": "layers/Engine/packages/root/tests",
            "PoP\\\\RoutingWP\\\\": "layers/Engine/packages/routing-wp/tests",
            "PoP\\\\Routing\\\\": "layers/Engine/packages/routing/tests",
            "PoP\\\\SPA\\\\": "layers/SiteBuilder/packages/spa/tests",
            "PoP\\\\SSG\\\\": "layers/SiteBuilder/packages/static-site-generator/tests",
            "PoP\\\\SiteWP\\\\": "layers/SiteBuilder/packages/site-wp/tests",
            "PoP\\\\Site\\\\": "layers/SiteBuilder/packages/site/tests",
            "PoP\\\\TraceTools\\\\": "layers/Engine/packages/trace-tools/tests",
            "PoP\\\\TranslationWP\\\\": "layers/Engine/packages/translation-wp/tests",
            "PoP\\\\Translation\\\\": "layers/Engine/packages/translation/tests"
        }
    },
    "extra": {
        "wordpress-install-dir": "vendor/wordpress/wordpress",
        "merge-plugin": {
            "include": [
                "composer.local.json"
            ],
            "recurse": true,
            "replace": false,
            "ignore-duplicates": false,
            "merge-dev": true,
            "merge-extra": false,
            "merge-extra-deep": false,
            "merge-scripts": false
        }
    },
    "replace": {
        "getpop/access-control": "self.version",
        "getpop/api": "self.version",
        "getpop/api-clients": "self.version",
        "getpop/api-endpoints": "self.version",
        "getpop/api-endpoints-for-wp": "self.version",
        "getpop/api-graphql": "self.version",
        "getpop/api-mirrorquery": "self.version",
        "getpop/api-rest": "self.version",
        "getpop/application": "self.version",
        "getpop/application-wp": "self.version",
        "getpop/cache-control": "self.version",
        "getpop/component-model": "self.version",
        "getpop/component-model-configuration": "self.version",
        "getpop/configurable-schema-feedback": "self.version",
        "getpop/definitionpersistence": "self.version",
        "getpop/definitions": "self.version",
        "getpop/definitions-base36": "self.version",
        "getpop/definitions-emoji": "self.version",
        "getpop/engine": "self.version",
        "getpop/engine-wp": "self.version",
        "getpop/engine-wp-bootloader": "self.version",
        "getpop/field-query": "self.version",
        "getpop/filestore": "self.version",
        "getpop/function-fields": "self.version",
        "getpop/guzzle-helpers": "self.version",
        "getpop/hooks": "self.version",
        "getpop/hooks-wp": "self.version",
        "getpop/loosecontracts": "self.version",
        "getpop/mandatory-directives-by-configuration": "self.version",
        "getpop/migrate-api": "self.version",
        "getpop/migrate-api-graphql": "self.version",
        "getpop/migrate-component-model": "self.version",
        "getpop/migrate-component-model-configuration": "self.version",
        "getpop/migrate-engine": "self.version",
        "getpop/migrate-engine-wp": "self.version",
        "getpop/migrate-static-site-generator": "self.version",
        "getpop/modulerouting": "self.version",
        "getpop/multisite": "self.version",
        "getpop/query-parsing": "self.version",
        "getpop/resourceloader": "self.version",
        "getpop/resources": "self.version",
        "getpop/root": "self.version",
        "getpop/routing": "self.version",
        "getpop/routing-wp": "self.version",
        "getpop/site": "self.version",
        "getpop/site-wp": "self.version",
        "getpop/spa": "self.version",
        "getpop/static-site-generator": "self.version",
        "getpop/trace-tools": "self.version",
        "getpop/translation": "self.version",
        "getpop/translation-wp": "self.version",
        "graphql-api/convert-case-directives": "self.version",
        "graphql-api/graphql-api-for-wp": "self.version",
        "graphql-api/schema-feedback": "self.version",
        "graphql-by-pop/graphql-clients-for-wp": "self.version",
        "graphql-by-pop/graphql-endpoint-for-wp": "self.version",
        "graphql-by-pop/graphql-parser": "self.version",
        "graphql-by-pop/graphql-query": "self.version",
        "graphql-by-pop/graphql-request": "self.version",
        "graphql-by-pop/graphql-server": "self.version",
        "leoloso/examples-for-pop": "self.version",
        "pop-migrate-everythingelse/cssconverter": "self.version",
        "pop-migrate-everythingelse/ssr": "self.version",
        "pop-schema/basic-directives": "self.version",
        "pop-schema/block-metadata-for-wp": "self.version",
        "pop-schema/categories": "self.version",
        "pop-schema/categories-wp": "self.version",
        "pop-schema/cdn-directive": "self.version",
        "pop-schema/comment-mutations": "self.version",
        "pop-schema/comment-mutations-wp": "self.version",
        "pop-schema/commentmeta": "self.version",
        "pop-schema/commentmeta-wp": "self.version",
        "pop-schema/comments": "self.version",
        "pop-schema/comments-wp": "self.version",
        "pop-schema/convert-case-directives": "self.version",
        "pop-schema/custompost-mutations": "self.version",
        "pop-schema/custompost-mutations-wp": "self.version",
        "pop-schema/custompostmedia": "self.version",
        "pop-schema/custompostmedia-mutations": "self.version",
        "pop-schema/custompostmedia-mutations-wp": "self.version",
        "pop-schema/custompostmedia-wp": "self.version",
        "pop-schema/custompostmeta": "self.version",
        "pop-schema/custompostmeta-wp": "self.version",
        "pop-schema/customposts": "self.version",
        "pop-schema/customposts-wp": "self.version",
        "pop-schema/event-mutations": "self.version",
        "pop-schema/event-mutations-wp-em": "self.version",
        "pop-schema/events": "self.version",
        "pop-schema/events-wp-em": "self.version",
        "pop-schema/everythingelse": "self.version",
        "pop-schema/everythingelse-wp": "self.version",
        "pop-schema/generic-customposts": "self.version",
        "pop-schema/google-translate-directive": "self.version",
        "pop-schema/google-translate-directive-for-customposts": "self.version",
        "pop-schema/highlights": "self.version",
        "pop-schema/highlights-wp": "self.version",
        "pop-schema/locationposts": "self.version",
        "pop-schema/locationposts-wp": "self.version",
        "pop-schema/locations": "self.version",
        "pop-schema/locations-wp-em": "self.version",
        "pop-schema/media": "self.version",
        "pop-schema/media-wp": "self.version",
        "pop-schema/menus": "self.version",
        "pop-schema/menus-wp": "self.version",
        "pop-schema/meta": "self.version",
        "pop-schema/metaquery": "self.version",
        "pop-schema/metaquery-wp": "self.version",
        "pop-schema/migrate-categories": "self.version",
        "pop-schema/migrate-categories-wp": "self.version",
        "pop-schema/migrate-commentmeta": "self.version",
        "pop-schema/migrate-commentmeta-wp": "self.version",
        "pop-schema/migrate-comments": "self.version",
        "pop-schema/migrate-comments-wp": "self.version",
        "pop-schema/migrate-custompostmedia": "self.version",
        "pop-schema/migrate-custompostmedia-wp": "self.version",
        "pop-schema/migrate-custompostmeta": "self.version",
        "pop-schema/migrate-custompostmeta-wp": "self.version",
        "pop-schema/migrate-customposts": "self.version",
        "pop-schema/migrate-customposts-wp": "self.version",
        "pop-schema/migrate-events": "self.version",
        "pop-schema/migrate-events-wp-em": "self.version",
        "pop-schema/migrate-everythingelse": "self.version",
        "pop-schema/migrate-locations": "self.version",
        "pop-schema/migrate-locations-wp-em": "self.version",
        "pop-schema/migrate-media": "self.version",
        "pop-schema/migrate-media-wp": "self.version",
        "pop-schema/migrate-meta": "self.version",
        "pop-schema/migrate-metaquery": "self.version",
        "pop-schema/migrate-metaquery-wp": "self.version",
        "pop-schema/migrate-pages": "self.version",
        "pop-schema/migrate-pages-wp": "self.version",
        "pop-schema/migrate-post-tags": "self.version",
        "pop-schema/migrate-post-tags-wp": "self.version",
        "pop-schema/migrate-posts": "self.version",
        "pop-schema/migrate-posts-wp": "self.version",
        "pop-schema/migrate-queriedobject": "self.version",
        "pop-schema/migrate-queriedobject-wp": "self.version",
        "pop-schema/migrate-tags": "self.version",
        "pop-schema/migrate-tags-wp": "self.version",
        "pop-schema/migrate-taxonomies": "self.version",
        "pop-schema/migrate-taxonomies-wp": "self.version",
        "pop-schema/migrate-taxonomymeta": "self.version",
        "pop-schema/migrate-taxonomymeta-wp": "self.version",
        "pop-schema/migrate-taxonomyquery": "self.version",
        "pop-schema/migrate-taxonomyquery-wp": "self.version",
        "pop-schema/migrate-usermeta": "self.version",
        "pop-schema/migrate-usermeta-wp": "self.version",
        "pop-schema/migrate-users": "self.version",
        "pop-schema/migrate-users-wp": "self.version",
        "pop-schema/notifications": "self.version",
        "pop-schema/notifications-wp": "self.version",
        "pop-schema/pages": "self.version",
        "pop-schema/pages-wp": "self.version",
        "pop-schema/post-mutations": "self.version",
        "pop-schema/post-tags": "self.version",
        "pop-schema/post-tags-wp": "self.version",
        "pop-schema/posts": "self.version",
        "pop-schema/posts-wp": "self.version",
        "pop-schema/queriedobject": "self.version",
        "pop-schema/queriedobject-wp": "self.version",
        "pop-schema/schema-commons": "self.version",
        "pop-schema/stances": "self.version",
        "pop-schema/stances-wp": "self.version",
        "pop-schema/tags": "self.version",
        "pop-schema/tags-wp": "self.version",
        "pop-schema/taxonomies": "self.version",
        "pop-schema/taxonomies-wp": "self.version",
        "pop-schema/taxonomymeta": "self.version",
        "pop-schema/taxonomymeta-wp": "self.version",
        "pop-schema/taxonomyquery": "self.version",
        "pop-schema/taxonomyquery-wp": "self.version",
        "pop-schema/translate-directive": "self.version",
        "pop-schema/translate-directive-acl": "self.version",
        "pop-schema/user-roles": "self.version",
        "pop-schema/user-roles-access-control": "self.version",
        "pop-schema/user-roles-acl": "self.version",
        "pop-schema/user-roles-wp": "self.version",
        "pop-schema/user-state": "self.version",
        "pop-schema/user-state-access-control": "self.version",
        "pop-schema/user-state-mutations": "self.version",
        "pop-schema/user-state-mutations-wp": "self.version",
        "pop-schema/user-state-wp": "self.version",
        "pop-schema/usermeta": "self.version",
        "pop-schema/usermeta-wp": "self.version",
        "pop-schema/users": "self.version",
        "pop-schema/users-wp": "self.version",
        "pop-sites-wassup/comment-mutations": "self.version",
        "pop-sites-wassup/contactus-mutations": "self.version",
        "pop-sites-wassup/contactuser-mutations": "self.version",
        "pop-sites-wassup/custompost-mutations": "self.version",
        "pop-sites-wassup/custompostlink-mutations": "self.version",
        "pop-sites-wassup/event-mutations": "self.version",
        "pop-sites-wassup/eventlink-mutations": "self.version",
        "pop-sites-wassup/everythingelse-mutations": "self.version",
        "pop-sites-wassup/flag-mutations": "self.version",
        "pop-sites-wassup/form-mutations": "self.version",
        "pop-sites-wassup/gravityforms-mutations": "self.version",
        "pop-sites-wassup/highlight-mutations": "self.version",
        "pop-sites-wassup/location-mutations": "self.version",
        "pop-sites-wassup/locationpost-mutations": "self.version",
        "pop-sites-wassup/locationpostlink-mutations": "self.version",
        "pop-sites-wassup/newsletter-mutations": "self.version",
        "pop-sites-wassup/notification-mutations": "self.version",
        "pop-sites-wassup/post-mutations": "self.version",
        "pop-sites-wassup/postlink-mutations": "self.version",
        "pop-sites-wassup/share-mutations": "self.version",
        "pop-sites-wassup/socialnetwork-mutations": "self.version",
        "pop-sites-wassup/stance-mutations": "self.version",
        "pop-sites-wassup/system-mutations": "self.version",
        "pop-sites-wassup/user-state-mutations": "self.version",
        "pop-sites-wassup/volunteer-mutations": "self.version",
        "pop-sites-wassup/wassup": "self.version"
    },
    "authors": [
        {
            "name": "Leonardo Losoviz",
            "email": "leo@getpop.org",
            "homepage": "https://getpop.org"
        }
    ],
    "description": "Monorepo for all the PoP packages",
    "license": "GPL-2.0-or-later",
    "config": {
        "sort-packages": true,
        "platform-check": false
    },
    "repositories": [
        {
            "type": "composer",
            "url": "https://wpackagist.org"
        },
        {
            "type": "vcs",
            "url": "https://github.com/leoloso/wp-muplugin-loader.git"
        },
        {
            "type": "vcs",
            "url": "https://github.com/mcaskill/composer-merge-plugin.git"
        }
    ],
    "scripts": {
        "test": "phpunit",
        "check-style": "phpcs -n src $(monorepo-builder source-packages --subfolder=src --subfolder=tests)",
        "fix-style": "phpcbf -n src $(monorepo-builder source-packages --subfolder=src --subfolder=tests)",
        "analyse": "ci/phpstan.sh \\". $(monorepo-builder source-packages --skip-unmigrated)\\"",
        "preview-src-downgrade": "rector process $(monorepo-builder source-packages --subfolder=src) --config=rector-downgrade-code.php --ansi --dry-run || true",
        "preview-vendor-downgrade": "layers/Engine/packages/root/ci/downgrade_code.sh 7.1 rector-downgrade-code.php --dry-run || true",
        "preview-code-downgrade": [
            "@preview-src-downgrade",
            "@preview-vendor-downgrade"
        ],
        "build-server": [
            "lando init --source remote --remote-url https://wordpress.org/latest.tar.gz --recipe wordpress --webroot wordpress --name graphql-api-dev",
            "@start-server"
        ],
        "start-server": [
            "cd layers/GraphQLAPIForWP/plugins/graphql-api-for-wp && composer install",
            "lando start"
        ],
        "rebuild-server": "lando rebuild -y",
        "merge-monorepo": "monorepo-builder merge --ansi",
        "propagate-monorepo": "monorepo-builder propagate --ansi",
        "validate-monorepo": "monorepo-builder validate --ansi",
        "release": "monorepo-builder release patch --ansi"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
', $manipulator->getContents());
    }
}
