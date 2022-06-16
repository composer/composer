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

namespace Composer\Package\Dumper;

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Package\RootPackageInterface;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayDumper
{
    /**
     * @return array<string, mixed>
     */
    public function dump(PackageInterface $package): array
    {
        $keys = [
            'binaries' => 'bin',
            'type',
            'extra',
            'installationSource' => 'installation-source',
            'autoload',
            'devAutoload' => 'autoload-dev',
            'notificationUrl' => 'notification-url',
            'includePaths' => 'include-path',
        ];

        $data = [];
        $data['name'] = $package->getPrettyName();
        $data['version'] = $package->getPrettyVersion();
        $data['version_normalized'] = $package->getVersion();

        if ($package->getTargetDir()) {
            $data['target-dir'] = $package->getTargetDir();
        }

        if ($package->getSourceType()) {
            $data['source']['type'] = $package->getSourceType();
            $data['source']['url'] = $package->getSourceUrl();
            if (null !== ($value = $package->getSourceReference())) {
                $data['source']['reference'] = $value;
            }
            if ($mirrors = $package->getSourceMirrors()) {
                $data['source']['mirrors'] = $mirrors;
            }
        }

        if ($package->getDistType()) {
            $data['dist']['type'] = $package->getDistType();
            $data['dist']['url'] = $package->getDistUrl();
            if (null !== ($value = $package->getDistReference())) {
                $data['dist']['reference'] = $value;
            }
            if (null !== ($value = $package->getDistSha1Checksum())) {
                $data['dist']['shasum'] = $value;
            }
            if ($mirrors = $package->getDistMirrors()) {
                $data['dist']['mirrors'] = $mirrors;
            }
        }

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if ($links = $package->{'get'.ucfirst($opts['method'])}()) {
                foreach ($links as $link) {
                    $data[$type][$link->getTarget()] = $link->getPrettyConstraint();
                }
                ksort($data[$type]);
            }
        }

        if ($packages = $package->getSuggests()) {
            ksort($packages);
            $data['suggest'] = $packages;
        }

        if ($package->getReleaseDate() instanceof \DateTimeInterface) {
            $data['time'] = $package->getReleaseDate()->format(DATE_RFC3339);
        }

        if ($package->isDefaultBranch()) {
            $data['default-branch'] = true;
        }

        $data = $this->dumpValues($package, $keys, $data);

        if ($package instanceof CompletePackageInterface) {
            if ($package->getArchiveName()) {
                $data['archive']['name'] = $package->getArchiveName();
            }
            if ($package->getArchiveExcludes()) {
                $data['archive']['exclude'] = $package->getArchiveExcludes();
            }

            $keys = [
                'scripts',
                'license',
                'authors',
                'description',
                'homepage',
                'keywords',
                'repositories',
                'support',
                'funding',
            ];

            $data = $this->dumpValues($package, $keys, $data);

            if (isset($data['keywords']) && \is_array($data['keywords'])) {
                sort($data['keywords']);
            }

            if ($package->isAbandoned()) {
                $data['abandoned'] = $package->getReplacementPackage() ?: true;
            }
        }

        if ($package instanceof RootPackageInterface) {
            $minimumStability = $package->getMinimumStability();
            if ($minimumStability) {
                $data['minimum-stability'] = $minimumStability;
            }
        }

        if (\count($package->getTransportOptions()) > 0) {
            $data['transport-options'] = $package->getTransportOptions();
        }

        return $data;
    }

    /**
     * @param array<int|string, string> $keys
     * @param array<string, mixed>      $data
     *
     * @return array<string, mixed>
     */
    private function dumpValues(PackageInterface $package, array $keys, array $data): array
    {
        foreach ($keys as $method => $key) {
            if (is_numeric($method)) {
                $method = $key;
            }

            $getter = 'get'.ucfirst($method);
            $value = $package->$getter();

            if (null !== $value && !(\is_array($value) && 0 === \count($value))) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
