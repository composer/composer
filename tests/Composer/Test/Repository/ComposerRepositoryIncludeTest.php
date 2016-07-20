<?php
/**
 * Created by PhpStorm.
 * User: mix
 * Date: 19.07.16
 * Time: 16:58
 */

namespace Composer\Repository;

use Composer\Cache;
use Composer\IO\NullIO;
use Composer\Test\Mock\FactoryMock;
use Composer\Test\Mock\RemoteFilesystemMock;
use Composer\TestCase;

class ComposerRepositoryIncludeTest extends TestCase
{
    private $packageName = "aaa/bb";
    private $domain = "http://example.org/";
    private $url;
    private $baseUrl;
    private $packageDist;


    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->url = "http://example.org/base/" . $this->packageName . "/1.0.0.zip";
        $this->baseUrl = $this->packageName . "/1.0.0.zip";

        $this->packageDist = array(
            $this->packageName => array(
                "1.0.0" => array(
                    "name" => $this->packageName,
                    "version" => "1.0.0",
                    "version_normalized" => "1.0.0.0",
                    "source" => array(
                        "type" => "git",
                        "url" => "git@example.org:" . $this->packageName,
                        "reference" => "fe0936ee26643249e916849d48e3a51d5f5e278b"
                    ),
                    "dist" => array(
                        "type" => "zip",
                        "url" => $this->url,
                        "reference" => "fe0936ee26643249e916849d48e3a51d5f5e278b",
                        "shasum" => "41023cff41165723b73cb883afc4190dde346cb4"
                    ),
                    'uid' => 1
                )
            )
        );
    }

    public function distUrlDataProvider()
    {
        $out = array();

        $domain = $this->domain;
        $url = $this->url;
        $name = $this->packageName;

        $packageDist = $this->packageDist;

        #0
        $out[] = array(
            array('url' => $domain),
            array($domain . 'packages.json' => json_encode(array('packages' => $packageDist))),
            $url
        );

        #1
        $out[] = array(
            array('url' => $domain . 'repo.json'),
            array($domain . 'repo.json' => json_encode(array('packages' => $packageDist))),
            $url
        );

        #2
        $out[] = array(
            array('url' => $domain . 'base/'),
            array($domain . 'base/packages.json' => json_encode(array('packages' => $packageDist))),
            $url
        );

        #3
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array($domain . 'base/repo.json' => json_encode(array('packages' => $packageDist))),
            $url
        );

        $package = $packageDist;
        $package[$name]['1.0.0']['dist']['url'] = "/base/" . $name . "/1.0.0.zip";

        #4
        $out[] = array(
            array('url' => $domain),
            array($domain . 'packages.json' => json_encode(array('packages' => $package))),
            $url
        );

        #5
        $out[] = array(
            array('url' => $domain . 'repo.json'),
            array($domain . 'repo.json' => json_encode(array('packages' => $package))),
            $url
        );

        #6
        $out[] = array(
            array('url' => $domain . 'base/'),
            array($domain . 'base/packages.json' => json_encode(array('packages' => $package))),
            $url
        );

        #7
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array($domain . 'base/repo.json' => json_encode(array('packages' => $package))),
            $url
        );

        $package = $packageDist;
        $package[$name]['1.0.0']['dist']['url']  =  $this->baseUrl;

        #8
        $out[] = array(
            array('url' => $this->domain),
            array($domain . 'packages.json' => json_encode(array('packages' => $package))),
            $domain . $this->baseUrl
        );

        #9
        $out[] = array(
            array('url' => $domain . 'repo.json'),
            array($domain . 'repo.json' => json_encode(array('packages' => $package))),
            $domain . $this->baseUrl
        );

        #10
        $out[] = array(
            array('url' => $domain . 'base/'),
            array($domain . 'base/packages.json' => json_encode(array('packages' => $package))),
            $url
        );

        #11
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array($domain . 'base/repo.json' => json_encode(array('packages' => $package))),
            $url
        );

        return $out;

    }

    public function includesDataProvider()
    {
        $out = array();

        $domain = $this->domain;
        $package = $this->packageDist;
        $url = $this->url;
        #0
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );
        #1
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );
        #2
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );
        #3
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );
        #4
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );
        #5
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "base/inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );

        #6
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array(
                $domain . 'base/repo.json' => json_encode(array('includes' => array('/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );

        #7
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array(
                $domain . 'base/repo.json' => json_encode(array('includes' => array('inc.json' => array('sha1' => '')))),
                $domain . "base/inc.json" => json_encode(array('packages' => $package)),
            ),
            $url
        );

        return $out;
    }

    public function providerDataProvider()
    {
        $out = array();


        $domain = $this->domain;
        $package = $this->packageDist;
        $url = $this->url;
        $name = $this->packageName;

        $root = array(
            "providers-url" =>"%package%.json",
            "providers" => array($this->packageName => array('sha256' => ""))
        );


        #0
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #1
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #2
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . "base/" . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #3
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . "base/" . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "providers" => array($this->packageName => array('sha256' => ""))
        );

        #4
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #5
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #6
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #7
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );

        return $out;
    }

    public function providerIncludesDataProvider()
    {
        $out = array();

        $domain = $this->domain;
        $package = $this->packageDist;
        $url = $this->url;
        $name = $this->packageName;

        $provider = array(
            "providers" => array($this->packageName => array('sha256' => ""))
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "provider-includes" => array("/inc.json" => array("sha256" => ""))
        );
        #0
        $out[] = array(
            array('url' => $domain . ""),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #1
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #2
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #3
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "provider-includes" => array("inc.json" => array("sha256" => ""))
        );
        #4
        $out[] = array(
            array('url' => $domain . ""),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #5
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #6
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . 'base/inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );
        #7
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . 'base/inc.json' => json_encode($provider),
                $domain . $name . ".json" =>  json_encode(array('packages' => $package))
            ),
            $url
        );


        return $out;
    }

    /**
     * @dataProvider providerDataProvider
     */
    public function testProviders($repoConfig, $urlMock, $url)
    {
        $this->makeTest($repoConfig, $urlMock, $url);
    }

    /**
     * @dataProvider distUrlDataProvider
     */
    public function testDistUrl($repoConfig, $urlMock, $url)
    {
        $this->makeTest($repoConfig, $urlMock, $url);
    }

    /**
     * @dataProvider includesDataProvider
     */
    public function testIncludes($repoConfig, $urlMock, $url)
    {
        $this->makeTest($repoConfig, $urlMock, $url);
    }

    /**
     * @dataProvider providerIncludesDataProvider
     */
    public function testProviderIncludes($repoConfig, $urlMock, $url)
    {
        $this->makeTest($repoConfig, $urlMock, $url);
    }

    public function makeTest($repoConfig, $urlMock, $url)
    {
        $rfs = new RemoteFilesystemMock($urlMock);
        $cache = new Cache(new NullIO(), 'NUL');

        $repo = new ComposerRepository($repoConfig, new NullIO, FactoryMock::createConfig(), null, $rfs, $cache);

        $package = $repo->findPackage($this->packageName, '1.0');
        $this->assertEquals($package->getName(), $this->packageName);
        $this->assertEquals($package->getDistUrl(), $url);
    }

}