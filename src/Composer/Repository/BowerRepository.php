<?php

/*
 * This file is part of Composer.
 *
 * (c) Niko Sams <niko.sams@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository;

use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\Bower\ListReader;
use Composer\Package\CompletePackage;
use Composer\Repository\Pear\ChannelInfo;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Util\RemoteFilesystem;
use Composer\Cache;
use Composer\Config;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Pool;
use Composer\Downloader\TransportException;
use Composer\Package\Loader\ArrayLoader;

/**
 * Builds list of package from Bower.
 *
 * Packages read from channel are named as 'bower-{channelName}/{packageName}'
 * and has aliased as 'bower-{channelAlias}/{packageName}'
 *
 * @author Niko Sams <niko.sams@gmail.com>
 */
class BowerRepository extends ArrayRepository
{
    private $url;
    private $io;
    private $rfs;
    private $versionParser;
    private $loader;
    private $cache;
    private $cacheTags;
    private $providers = array();

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null, RemoteFilesystem $rfs = null)
    {
        $urlBits = parse_url($repoConfig['url']);
        if (empty($urlBits['scheme']) || empty($urlBits['host'])) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$repoConfig['url']);
        }

        $this->url = rtrim($repoConfig['url'], '/');
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($this->io, $config);
        $this->versionParser = new VersionParser();
        $this->cache = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
        $this->cacheTags = new Cache($io, $config->get('cache-repo-dir').'/'.preg_replace('{[^a-z0-9.]}i', '-', $this->url).'/tags', 'a-z0-9.$');
        $this->cacheTags->gc(5*60, 1024*1024*10); //expire after 5 minutes
        $this->loader = new ArrayLoader();
    }

    protected function initialize()
    {
        parent::initialize();
    }

    public function hasProviders()
    {
        return true;
        $this->loadRootServerFile();

        return $this->hasProviders;
    }

    public function setRootAliases(array $rootAliases)
    {
        $this->rootAliases = $rootAliases;
    }

    public function resetPackageIds()
    {
        foreach ($this->providers as $name=>$packages) {
            foreach ($packages as $package) {
                $package->setId(-1);
            }
        }
    }

    public function whatProvides(Pool $pool, $name)
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        if (!preg_match('/^bower\/(.*)/', $name, $m)) {
            return array();
        }
        $bowerPackageName = $m[1];


        try {
            $packageContents = $this->rfs->getContents('bower.herokuapp.com', $this->url.'/packages/'.$bowerPackageName, false);
        } catch (TransportException $e) {
            if ($e->getCode() === 404) {
                return array();
            }
            throw $e;
        }
        $packageContents = json_decode($packageContents);
        if (!preg_match('/github\.com\/([^\/]*)\/([^\/]*)\.git$/', $packageContents->url, $m)) {
            throw new RuntimeException("invalid package url");
        }
        $repoUser = $m[1];
        $repoName = $m[2];

        $cacheKey = "$repoUser/$repoName";
        $tags = $this->cacheTags->read($cacheKey);
        if ($tags === false) {
            $githubTagsURL = sprintf('https://api.github.com/repos/%s/%s/tags?per_page=100', $repoUser, $repoName);
            $tags = $this->rfs->getContents('api.github.com', $githubTagsURL, false);
            $this->cacheTags->write($cacheKey, $tags);
        }
        $tags = json_decode($tags, true);

        $this->providers[$name] = array();
        foreach ($tags as $tag) {
            $cacheKey = "bowerjson/$repoUser/$repoName/".$tag['commit']['sha'];
            $contents = $this->cache->read($cacheKey);
            if ($contents === false) {
                $url = sprintf('https://github.com/%s/%s/raw/%s/bower.json', $repoUser, $repoName, $tag['name']);
                $this->io->write("Loading bower.json data for $repoUser/$repoName $tag[name]");
                try {
                    $contents = $this->rfs->getContents('api.github.com', $url, false);
                } catch (TransportException $e) {
                    if ($e->getCode() != 404) {
                        throw $e;
                    }
                    $contents = '';
                }
                $this->cache->write($cacheKey, $contents);
            }
            if ($contents !== '') {
                $contents = json_decode($contents, true);
                if (!$contents) {
                    $this->io->write("<warning>Invalid bower.json: $repoUser/$repoName $tag[name]</warning>");
                    continue;
                }
                $data = array(
                    'name' => $name,
                    'version' => $contents['version'],
                    'require' => array(),
                    'dist' => array(
                        'type' => 'zip',
                        'url' => $tag['zipball_url'],
                        'reference' => $tag['commit']['sha'],
                    ),
                    'source' => array(
                        'type' => 'git',
                        'url' => sprintf('https://github.com/%s/%s.git', $repoUser, $repoName),
                        'reference' => $tag['commit']['sha'],
                    )
                );
                if (isset($contents['dependencies'])) {
                    foreach ($contents['dependencies'] as $package=>$version) {
                        if ($version == 'latest') $version = '*';
                        $data['require']['bower/'.$package] = $version;
                    }
                }
                //ignore devDependencies for now
                $package = $this->loader->load($data, 'Composer\Package\CompletePackage');
                $package->setRepository($this);
                $this->providers[$name][] = $package;
            }
        }
        return $this->providers[$name];
    }

}
