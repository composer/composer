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

namespace Composer\Test\Json;

use Composer\Json\JsonManipulator;

class JsonManipulatorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider linkProvider
     */
    public function testAddLink($json, $type, $package, $constraint, $expected)
    {
        $manipulator = new JsonManipulator($json);
        $this->assertTrue($manipulator->addLink($type, $package, $constraint));
        $this->assertEquals($expected, $manipulator->getContents());
    }

    public function linkProvider()
    {
        return array(
            array(
                '{}',
                'require',
                'vendor/baz',
                'qux',
                "{\n".
"    \"require\": {\n".
"        \"vendor/baz\": \"qux\"\n".
"    }\n".
"}\n",
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
        );
    }

    /**
     * @dataProvider providerAddLinkAndSortPackages
     */
    public function testAddLinkAndSortPackages($json, $type, $package, $constraint, $sortPackages, $expected)
    {
        $manipulator = new JsonManipulator($json);
        $this->assertTrue($manipulator->addLink($type, $package, $constraint, $sortPackages));
        $this->assertEquals($expected, $manipulator->getContents());
    }

    public function providerAddLinkAndSortPackages()
    {
        return array(
            array(
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
            ),
            array(
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
            ),
            array(
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
            ),
        );
    }

    /**
     * @dataProvider removeSubNodeProvider
     */
    public function testRemoveSubNode($json, $name, $expected, $expectedContent = null)
    {
        $manipulator = new JsonManipulator($json);

        $this->assertEquals($expected, $manipulator->removeSubNode('repositories', $name));
        if (null !== $expectedContent) {
            $this->assertEquals($expectedContent, $manipulator->getContents());
        }
    }

    public function removeSubNodeProvider()
    {
        return array(
            'works on simple ones first' => array(
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
            ),
            'works on simple ones last' => array(
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
            ),
            'works on simple ones unique' => array(
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
            ),
            'works on simple ones middle' => array(
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
            ),
            'works on undefined ones' => array(
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
            ),
            'works on child having unmatched name' => array(
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
            ),
            'works on child having duplicate name' => array(
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
            ),
            'works on empty repos' => array(
                '{
    "repositories": {
    }
}',
                'bar',
                true,
            ),
            'works on empty repos2' => array(
                '{
    "repositories": {}
}',
                'bar',
                true,
            ),
            'works on missing repos' => array(
                "{\n}",
                'bar',
                true,
            ),
            'works on deep repos' => array(
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
            ),
            'works on deep repos with borked texts' => array(
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
            ),
            'works on deep repos with borked texts2' => array(
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
            ),
            'fails on deep arrays with borked texts' => array(
                '{
    "repositories": [
        {
            "package": { "bar": "ba[z" }
        }
    ]
}',
                'bar',
                false,
            ),
            'fails on deep arrays with borked texts2' => array(
                '{
    "repositories": [
        {
            "package": { "bar": "ba]z" }
        }
    ]
}',
                'bar',
                false,
            ),
        );
    }

    public function testRemoveSubNodeFromRequire()
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

        $this->assertTrue($manipulator->removeSubNode('require', 'package/c'));
        $this->assertTrue($manipulator->removeSubNode('require-dev', 'package/d'));
        $this->assertEquals('{
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

    public function testAddSubNodeInRequire()
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

        $this->assertTrue($manipulator->addSubNode('require', 'package/c', '*'));
        $this->assertTrue($manipulator->addSubNode('require-dev', 'package/e', '*'));
        $this->assertEquals('{
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

    public function testAddExtraWithPackage()
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

        $this->assertTrue($manipulator->addProperty('extra.foo-bar', true));
        $this->assertEquals('{
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

    public function testAddRepositoryCanInitializeEmptyRepositories()
    {
        $manipulator = new JsonManipulator('{
  "repositories": {
  }
}');

        $this->assertTrue($manipulator->addRepository('bar', array('type' => 'composer')));
        $this->assertEquals('{
  "repositories": {
    "bar": {
      "type": "composer"
    }
  }
}
', $manipulator->getContents());
    }

    public function testAddRepositoryCanInitializeFromScratch()
    {
        $manipulator = new JsonManipulator("{
\t\"a\": \"b\"
}");

        $this->assertTrue($manipulator->addRepository('bar2', array('type' => 'composer')));
        $this->assertEquals("{
\t\"a\": \"b\",
\t\"repositories\": {
\t\t\"bar2\": {
\t\t\t\"type\": \"composer\"
\t\t}
\t}
}
", $manipulator->getContents());
    }

    public function testAddRepositoryCanAdd()
    {
        $manipulator = new JsonManipulator('{
    "repositories": {
        "foo": {
            "type": "vcs",
            "url": "lala"
        }
    }
}');

        $this->assertTrue($manipulator->addRepository('bar', array('type' => 'composer')));
        $this->assertEquals('{
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

    public function testAddRepositoryCanOverrideDeepRepos()
    {
        $manipulator = new JsonManipulator('{
    "repositories": {
        "baz": {
            "type": "package",
            "package": {}
        }
    }
}');

        $this->assertTrue($manipulator->addRepository('baz', array('type' => 'composer')));
        $this->assertEquals('{
    "repositories": {
        "baz": {
            "type": "composer"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingEscapes()
    {
        $manipulator = new JsonManipulator('{
    "config": {
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('test', 'a\b'));
        $this->assertTrue($manipulator->addConfigSetting('test2', "a\nb\fa"));
        $this->assertEquals('{
    "config": {
        "test": "a\\\\b",
        "test2": "a\nb\fa"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingWorksFromScratch()
    {
        $manipulator = new JsonManipulator('{
}');

        $this->assertTrue($manipulator->addConfigSetting('foo.bar', 'baz'));
        $this->assertEquals('{
    "config": {
        "foo": {
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAdd()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": "bar"
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('bar', 'baz'));
        $this->assertEquals('{
    "config": {
        "foo": "bar",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwrite()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": "bar",
        "bar": "baz"
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('foo', 'zomg'));
        $this->assertEquals('{
    "config": {
        "foo": "zomg",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwriteNumbers()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "foo": 500
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('foo', 50));
        $this->assertEquals('{
    "config": {
        "foo": 50
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanOverwriteArrays()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        },
        "github-protocols": ["https"]
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('github-protocols', array('https', 'http')));
        $this->assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        },
        "github-protocols": ["https", "http"]
    }
}
', $manipulator->getContents());

        $this->assertTrue($manipulator->addConfigSetting('github-oauth', array('github.com' => 'bar', 'alt.example.org' => 'baz')));
        $this->assertEquals('{
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

    public function testAddConfigSettingCanAddSubKeyInEmptyConfig()
    {
        $manipulator = new JsonManipulator('{
    "config": {
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        $this->assertEquals('{
    "config": {
        "github-oauth": {
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddConfigSettingCanAddSubKeyInEmptyVal()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {},
        "github-oauth2": {
        }
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        $this->assertTrue($manipulator->addConfigSetting('github-oauth2.a.bar', 'baz2'));
        $this->assertTrue($manipulator->addConfigSetting('github-oauth3.b', 'c'));
        $this->assertEquals('{
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

    public function testAddConfigSettingCanAddSubKeyInHash()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        }
    }
}');

        $this->assertTrue($manipulator->addConfigSetting('github-oauth.bar', 'baz'));
        $this->assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo",
            "bar": "baz"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddRootSettingDoesNotBreakDots()
    {
        $manipulator = new JsonManipulator('{
    "github-oauth": {
        "github.com": "foo"
    }
}');

        $this->assertTrue($manipulator->addSubNode('github-oauth', 'bar', 'baz'));
        $this->assertEquals('{
    "github-oauth": {
        "github.com": "foo",
        "bar": "baz"
    }
}
', $manipulator->getContents());
    }

    public function testRemoveConfigSettingCanRemoveSubKeyInHash()
    {
        $manipulator = new JsonManipulator('{
    "config": {
        "github-oauth": {
            "github.com": "foo",
            "bar": "baz"
        }
    }
}');

        $this->assertTrue($manipulator->removeConfigSetting('github-oauth.bar'));
        $this->assertEquals('{
    "config": {
        "github-oauth": {
            "github.com": "foo"
        }
    }
}
', $manipulator->getContents());
    }

    public function testRemoveConfigSettingCanRemoveSubKeyInHashWithSiblings()
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

        $this->assertTrue($manipulator->removeConfigSetting('github-oauth.bar'));
        $this->assertEquals('{
    "config": {
        "foo": "bar",
        "github-oauth": {
            "github.com": "foo"
        }
    }
}
', $manipulator->getContents());
    }

    public function testAddMainKey()
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        $this->assertTrue($manipulator->addMainKey('bar', 'baz'));
        $this->assertEquals('{
    "foo": "bar",
    "bar": "baz"
}
', $manipulator->getContents());
    }

    public function testAddMainKeyWithContentHavingDollarSignFollowedByDigit()
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        $this->assertTrue($manipulator->addMainKey('bar', '$1baz'));
        $this->assertEquals('{
    "foo": "bar",
    "bar": "$1baz"
}
', $manipulator->getContents());
    }

    public function testAddMainKeyWithContentHavingDollarSignFollowedByDigit2()
    {
        $manipulator = new JsonManipulator('{}');

        $this->assertTrue($manipulator->addMainKey('foo', '$1bar'));
        $this->assertEquals('{
    "foo": "$1bar"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey()
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        $this->assertTrue($manipulator->addMainKey('foo', 'baz'));
        $this->assertEquals('{
    "foo": "baz"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey2()
    {
        $manipulator = new JsonManipulator('{
    "a": {
        "foo": "bar",
        "baz": "qux"
    },
    "foo": "bar",
    "baz": "bar"
}');

        $this->assertTrue($manipulator->addMainKey('foo', 'baz'));
        $this->assertTrue($manipulator->addMainKey('baz', 'quux'));
        $this->assertEquals('{
    "a": {
        "foo": "bar",
        "baz": "qux"
    },
    "foo": "baz",
    "baz": "quux"
}
', $manipulator->getContents());
    }

    public function testUpdateMainKey3()
    {
        $manipulator = new JsonManipulator('{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "bar"
    }
}');

        $this->assertTrue($manipulator->addMainKey('require-dev', array('foo' => 'qux')));
        $this->assertEquals('{
    "require": {
        "php": "5.*"
    },
    "require-dev": {
        "foo": "qux"
    }
}
', $manipulator->getContents());
    }

    public function testUpdateMainKeyWithContentHavingDollarSignFollowedByDigit()
    {
        $manipulator = new JsonManipulator('{
    "foo": "bar"
}');

        $this->assertTrue($manipulator->addMainKey('foo', '$1bar'));
        $this->assertEquals('{
    "foo": "$1bar"
}
', $manipulator->getContents());
    }

    public function testRemoveMainKey()
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

        $this->assertTrue($manipulator->removeMainKey('repositories'));
        $this->assertEquals('{
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

        $this->assertTrue($manipulator->removeMainKey('foo'));
        $this->assertEquals('{
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

        $this->assertTrue($manipulator->removeMainKey('require'));
        $this->assertTrue($manipulator->removeMainKey('require-dev'));
        $this->assertEquals('{
}
', $manipulator->getContents());
    }

    public function testIndentDetection()
    {
        $manipulator = new JsonManipulator('{

  "require": {
    "php": "5.*"
  }
}');

        $this->assertTrue($manipulator->addMainKey('require-dev', array('foo' => 'qux')));
        $this->assertEquals('{

  "require": {
    "php": "5.*"
  },
  "require-dev": {
    "foo": "qux"
  }
}
', $manipulator->getContents());
    }
}
