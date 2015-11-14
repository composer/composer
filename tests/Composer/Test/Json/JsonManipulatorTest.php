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
            'fails on deep repos with borked texts' => array(
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba{z" }
        }
    }
}',
                'bar',
                false,
            ),
            'fails on deep repos with borked texts2' => array(
                '{
    "repositories": {
        "foo": {
            "package": { "bar": "ba}z" }
        }
    }
}',
                'bar',
                false,
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
