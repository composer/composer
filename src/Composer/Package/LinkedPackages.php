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

namespace Composer\Package;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;

/**
 * Reads/writes the linked packages file (linked-packages.json), which maps package names to local paths.
 */
final class LinkedPackages
{
    public const FILENAME = 'linked-packages.json';

    /** @var JsonFile */
    private $file;

    /**
     * @param string $dir directory holding linked-packages.json
     */
    public function __construct(string $dir, ?IOInterface $io = null)
    {
        $this->file = new JsonFile(rtrim($dir, '/\\').'/'.self::FILENAME, null, $io);
    }

    /**
     * Defaults to the Composer home dir
     */
    public static function getDefaultDir(Config $config): string
    {
        return $config->get('home');
    }

    /**
     * Adds the require-linked requirements of the composer file to the root package, replacing its requirements for the same packages
     *
     * @return array<string, Link> the require-linked requirements
     */
    public static function addRequireLinked(RootPackageInterface $rootPackage, string $composerFile): array
    {
        $composerJson = (new JsonFile($composerFile))->read();
        $requireLinked = (new ArrayLoader())->parseLinks($rootPackage->getName(), $rootPackage->getPrettyVersion(), Link::TYPE_REQUIRE, $composerJson['require-linked'] ?? []);

        $rootPackage->setRequires(array_merge($rootPackage->getRequires(), $requireLinked));
        $rootPackage->setDevRequires(array_diff_key($rootPackage->getDevRequires(), $requireLinked));

        return $requireLinked;
    }

    public function getFile(): string
    {
        return $this->file->getPath();
    }

    /**
     * Adds or replaces a link
     *
     * @param string $path the package directory
     * @param string|null $version the version the package is exposed as, e.g. 1.x-dev
     */
    public function link(string $name, string $path, ?string $version = null): void
    {
        $url = realpath($path);
        if (false === $url || !is_dir($url)) {
            throw new \InvalidArgumentException('The directory "'.$path.'" does not exist.');
        }

        $link = ['url' => $url];
        if (null !== $version) {
            (new VersionParser())->normalize($version);
            $link['version'] = $version;
        }

        $this->write($name, $link);
    }

    /**
     * @return bool false if the package was not linked
     */
    public function unlink(string $name): bool
    {
        if (!isset($this->getLinks()[$name])) {
            return false;
        }

        $this->write($name, null);

        return true;
    }

    /**
     * @param list<string>|null $only package name patterns, null for all
     * @return array<string, array{url: string, version?: string}>
     */
    public function getLinks(?array $only = null): array
    {
        if (!$this->file->exists()) {
            return [];
        }

        /** @var array{packages?: array<string, array{url: string, version?: string}>} $data */
        $data = $this->file->read();
        $links = $data['packages'] ?? [];

        if (null !== $only) {
            $pattern = BasePackage::packageNamesToRegexp($only);
            $links = array_filter($links, static function (string $name) use ($pattern): bool {
                return Preg::isMatch($pattern, $name);
            }, ARRAY_FILTER_USE_KEY);
        }

        return $links;
    }

    /**
     * Creates one path repository per link, to be prepended to the repository manager
     *
     * @param list<string>|null $only package name patterns, null for all
     * @return list<RepositoryInterface>
     */
    public function createRepositories(RepositoryManager $rm, Config $config, ?array $only): array
    {
        $linkedConfig = $config->get('linked-packages');
        $baseOptions = isset($linkedConfig['symlink']) ? ['symlink' => $linkedConfig['symlink']] : [];

        $repositories = [];
        foreach ($this->getLinks($only) as $name => $link) {
            $options = $baseOptions;
            if (isset($link['version'])) {
                $options['versions'] = [$name => $link['version']];
            }

            $repositories[] = $rm->createRepository('path', ['url' => $link['url'], 'options' => $options]);
        }

        return $repositories;
    }

    /**
     * @param array{url: string, version?: string}|null $link null to remove the link
     */
    private function write(string $name, ?array $link): void
    {
        $data = $this->file->exists() ? $this->file->read() : [];

        if (null === $link) {
            unset($data['packages'][$name]);
        } else {
            $data['packages'][$name] = $link;
        }

        $this->file->write($data);
    }
}
