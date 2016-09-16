<?php
/**
 * Created by PhpStorm.
 * User: mix
 * Date: 19.07.16
 * Time: 16:58
 */

namespace Composer\Repository;

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

    public function dataProvider()
    {
        $out = array();
        $domain = $this->domain;
        $packageDist = $this->packageDist;
        $url = $this->url;
        $packageName = $this->packageName;

        $root = array(
            "providers-url" =>"%package%.json",
            "providers" => array($this->packageName => array('sha256' => ""))
        );

        /// test root package

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
        $package[$packageName]['1.0.0']['dist']['url'] = "/base/" . $packageName . "/1.0.0.zip";

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
        $package[$packageName]['1.0.0']['dist']['url']  =  $this->baseUrl;

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


        /// test providers

        #12
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #13
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #14
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . "base/" . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #15
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . "base/" . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "providers" => array($this->packageName => array('sha256' => ""))
        );

        #16
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #17
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #18
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #19
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );

        /// test includes

        #20
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );
        #21
        $out[] = array(
            array('url' => $domain),
            array(
                $domain . 'packages.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );
        #22
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );
        #23
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );
        #24
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode(array('includes' => array( '/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );
        #25
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode(array('includes' => array( 'inc.json' => array('sha1' => '')))),
                $domain . "base/inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );

        #26
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array(
                $domain . 'base/repo.json' => json_encode(array('includes' => array('/inc.json' => array('sha1' => '')))),
                $domain . "inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );

        #27
        $out[] = array(
            array('url' => $domain . 'base/repo.json'),
            array(
                $domain . 'base/repo.json' => json_encode(array('includes' => array('inc.json' => array('sha1' => '')))),
                $domain . "base/inc.json" => json_encode(array('packages' => $packageDist)),
            ),
            $url
        );

        /// test includes data providers

        $provider = array(
            "providers" => array($this->packageName => array('sha256' => ""))
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "provider-includes" => array("/inc.json" => array("sha256" => ""))
        );
        #28
        $out[] = array(
            array('url' => $domain . ""),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #29
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #30
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #31
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );

        $root = array(
            "providers-url" =>"/%package%.json",
            "provider-includes" => array("inc.json" => array("sha256" => ""))
        );
        #32
        $out[] = array(
            array('url' => $domain . ""),
            array(
                $domain . 'packages.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #33
        $out[] = array(
            array('url' => $domain . "repo.json"),
            array(
                $domain . 'repo.json' => json_encode($root),
                $domain . 'inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #34
        $out[] = array(
            array('url' => $domain . "base"),
            array(
                $domain . 'base/packages.json' => json_encode($root),
                $domain . 'base/inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );
        #35
        $out[] = array(
            array('url' => $domain . "base/repo.json"),
            array(
                $domain . 'base/repo.json' => json_encode($root),
                $domain . 'base/inc.json' => json_encode($provider),
                $domain . $packageName . ".json" =>  json_encode(array('packages' => $packageDist))
            ),
            $url
        );

        /// test query string

        #36
        $path = "http://example.com/index.php?path=/my/repo.json";
        $out[] = array(
            array('url' => $path),
            array($path => json_encode(array('packages' => $this->packageDist))),
            $this->url
        );


        #37
        $path = "http://example.com/index.php#repo.json";
        $out[] = array(
            array('url' => $path),
            array($path => json_encode(array('packages' => $this->packageDist))),
            $this->url
        );

        return $out;
    }

    /**
     * @dataProvider dataProvider
     */
    public function testRepository($repoConfig, $urlMock, $url)
    {
        $rfs = new RemoteFilesystemMock($urlMock);
        $repo = new ComposerRepository($repoConfig, new NullIO, FactoryMock::createConfig(), null, $rfs);
        $package = $repo->findPackage($this->packageName, '1.0');
        $this->assertEquals($package->getName(), $this->packageName);
        $this->assertEquals($package->getDistUrl(), $url);
    }

}