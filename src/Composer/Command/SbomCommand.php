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

namespace Composer\Command;

use Composer\Composer;
use Composer\Console\Input\InputOption;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\RepositoryUtils;
use Composer\Spdx\SpdxLicenses;
use Composer\Util\PackageSorter;
use Composer\Util\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SbomCommand extends BaseCommand
{
    public const FORMAT_CYCLONEDX = 'cyclonedx';
    public const FORMAT_SPDX = 'spdx';
    public const FORMATS = [self::FORMAT_CYCLONEDX, self::FORMAT_SPDX];

    /** @var SpdxLicenses|null */
    private $spdxLicenses;

    protected function configure(): void
    {
        $this
            ->setName('sbom')
            ->setDescription('Generates a Software Bill of Materials (SBOM) for the dependencies')
            ->setDefinition([
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: cyclonedx (CycloneDX 1.6 JSON) or spdx (SPDX 2.3 JSON)', self::FORMAT_CYCLONEDX, self::FORMATS),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Excludes require-dev packages.'),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'Uses the lock file instead of installed packages.'),
            ])
            ->setHelp(
                <<<EOT
The <info>sbom</info> command outputs a Software Bill of Materials of the
installed dependencies, in CycloneDX 1.6 JSON or SPDX 2.3 JSON format.

Use --locked to generate it from composer.lock instead of what's currently
installed in the vendor directory.

Read more at https://getcomposer.org/doc/03-cli.md#sbom
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'sbom', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $format = $input->getOption('format');
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported format "%s". Must be one of: %s.', $format, implode(', ', self::FORMATS)));
        }

        $noDev = (bool) $input->getOption('no-dev');
        $root = $composer->getPackage();

        if ($input->getOption('locked')) {
            $locker = $composer->getLocker();
            if (!$locker->isLocked()) {
                throw new \UnexpectedValueException('Valid composer.json and composer.lock files are required to run this command with --locked');
            }
            $packages = $locker->getLockedRepository(!$noDev)->getPackages();
            $devPackageNames = $locker->getDevPackageNames();
        } else {
            $repo = $composer->getRepositoryManager()->getLocalRepository();
            $packages = $repo->getPackages();
            if ($noDev) {
                $packages = RepositoryUtils::filterRequiredPackages($packages, $root);
            }
            $devPackageNames = $repo->getDevPackageNames();
        }

        $packages = array_values(array_filter($packages, static function (PackageInterface $package): bool {
            return !$package instanceof AliasPackage;
        }));
        $packages = PackageSorter::sortPackagesAlphabetically($packages);

        $packagesByName = [];
        foreach ($packages as $package) {
            $packagesByName[$package->getName()] = $package;
        }
        foreach ($packages as $package) {
            foreach ($package->getNames() as $name) {
                if (!isset($packagesByName[$name])) {
                    $packagesByName[$name] = $package;
                }
            }
        }

        $rootRequires = $root->getRequires();
        $rootDevRequires = $noDev ? [] : $root->getDevRequires();

        if ($format === self::FORMAT_SPDX) {
            $sbom = $this->buildSpdx($root, $packages, $packagesByName, $rootRequires, $rootDevRequires);
        } else {
            $sbom = $this->buildCycloneDx($root, $packages, $packagesByName, $rootRequires, $rootDevRequires, array_flip($devPackageNames));
        }

        $this->getIO()->write(JsonFile::encode($sbom));

        return 0;
    }

    /**
     * @param list<PackageInterface>           $packages
     * @param array<string, PackageInterface>  $packagesByName
     * @param array<string, Link>              $rootRequires
     * @param array<string, Link>              $rootDevRequires
     * @param array<string, int>               $devPackageNames
     * @return array<string, mixed>
     */
    private function buildCycloneDx(PackageInterface $root, array $packages, array $packagesByName, array $rootRequires, array $rootDevRequires, array $devPackageNames): array
    {
        $rootComponent = $this->buildCycloneDxComponent($root, 'application');

        $components = [];
        $dependencies = [[
            'ref' => $rootComponent['bom-ref'],
            'dependsOn' => array_map([$this, 'getPurl'], $this->resolveLinks(array_merge($rootRequires, $rootDevRequires), $packagesByName)),
        ]];
        foreach ($packages as $package) {
            $component = $this->buildCycloneDxComponent($package, 'library');
            $component['scope'] = isset($devPackageNames[$package->getName()]) ? 'optional' : 'required';
            $components[] = $component;

            $dependencies[] = [
                'ref' => $component['bom-ref'],
                'dependsOn' => array_map([$this, 'getPurl'], $this->resolveLinks($package->getRequires(), $packagesByName)),
            ];
        }

        return [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'serialNumber' => 'urn:uuid:'.$this->generateUuid(),
            'version' => 1,
            'metadata' => [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'tools' => [
                    'components' => [[
                        'type' => 'application',
                        'name' => 'composer',
                        'version' => Composer::getVersion(),
                    ]],
                ],
                'component' => $rootComponent,
            ],
            'components' => $components,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCycloneDxComponent(PackageInterface $package, string $type): array
    {
        $purl = $this->getPurl($package);
        $component = [
            'type' => $type,
            'bom-ref' => $purl,
        ];

        $nameParts = explode('/', $package->getPrettyName(), 2);
        if (count($nameParts) === 2) {
            $component['group'] = $nameParts[0];
            $component['name'] = $nameParts[1];
        } else {
            $component['name'] = $nameParts[0];
        }
        $component['version'] = $package->getPrettyVersion();

        $licenses = $this->getLicenses($package);
        if (count($licenses) > 0) {
            $expression = $this->getLicenseExpression($licenses);
            if (count($licenses) > 1 && $expression !== null) {
                $component['licenses'] = [['expression' => $expression]];
            } else {
                $component['licenses'] = array_map(function (string $license): array {
                    if ($this->getSpdxLicenses()->getLicenseByIdentifier($license) !== null) {
                        return ['license' => ['id' => $license]];
                    }

                    return ['license' => ['name' => $license]];
                }, $licenses);
            }
        }

        $component['purl'] = $purl;

        $externalReferences = [];
        if (null !== $package->getSourceUrl()) {
            $reference = ['type' => 'vcs', 'url' => Url::sanitize($package->getSourceUrl())];
            if (null !== $package->getSourceReference()) {
                $reference['comment'] = 'reference: '.$package->getSourceReference();
            }
            $externalReferences[] = $reference;
        }
        if (null !== $package->getDistUrl()) {
            $reference = ['type' => 'distribution', 'url' => Url::sanitize($package->getDistUrl())];
            if (null !== $package->getDistReference()) {
                $reference['comment'] = 'reference: '.$package->getDistReference();
            }
            if (null !== $package->getDistSha1Checksum() && '' !== $package->getDistSha1Checksum()) {
                $reference['hashes'] = [['alg' => 'SHA-1', 'content' => $package->getDistSha1Checksum()]];
            }
            $externalReferences[] = $reference;
        }
        if (count($externalReferences) > 0) {
            $component['externalReferences'] = $externalReferences;
        }

        return $component;
    }

    /**
     * @param list<PackageInterface>           $packages
     * @param array<string, PackageInterface>  $packagesByName
     * @param array<string, Link>              $rootRequires
     * @param array<string, Link>              $rootDevRequires
     * @return array<string, mixed>
     */
    private function buildSpdx(PackageInterface $root, array $packages, array $packagesByName, array $rootRequires, array $rootDevRequires): array
    {
        $ids = [];
        $getId = static function (PackageInterface $package) use (&$ids): string {
            $key = spl_object_hash($package);
            if (!isset($ids[$key])) {
                $id = $base = 'SPDXRef-Package-'.str_replace(['/', '_'], '-', $package->getName());
                for ($i = 2; in_array($id, $ids, true); $i++) {
                    $id = $base.'-'.$i;
                }
                $ids[$key] = $id;
            }

            return $ids[$key];
        };

        $spdxPackages = [$this->buildSpdxPackage($root, $getId($root))];
        $relationships = [[
            'spdxElementId' => 'SPDXRef-DOCUMENT',
            'relationshipType' => 'DESCRIBES',
            'relatedSpdxElement' => $getId($root),
        ]];

        foreach ($this->resolveLinks($rootRequires, $packagesByName) as $dependency) {
            $relationships[] = [
                'spdxElementId' => $getId($root),
                'relationshipType' => 'DEPENDS_ON',
                'relatedSpdxElement' => $getId($dependency),
            ];
        }
        foreach ($this->resolveLinks($rootDevRequires, $packagesByName) as $dependency) {
            $relationships[] = [
                'spdxElementId' => $getId($dependency),
                'relationshipType' => 'DEV_DEPENDENCY_OF',
                'relatedSpdxElement' => $getId($root),
            ];
        }

        foreach ($packages as $package) {
            $spdxPackages[] = $this->buildSpdxPackage($package, $getId($package));
            foreach ($this->resolveLinks($package->getRequires(), $packagesByName) as $dependency) {
                $relationships[] = [
                    'spdxElementId' => $getId($package),
                    'relationshipType' => 'DEPENDS_ON',
                    'relatedSpdxElement' => $getId($dependency),
                ];
            }
        }

        return [
            'spdxVersion' => 'SPDX-2.3',
            'dataLicense' => 'CC0-1.0',
            'SPDXID' => 'SPDXRef-DOCUMENT',
            'name' => $root->getPrettyName(),
            'documentNamespace' => 'https://spdx.org/spdxdocs/'.rawurlencode($root->getPrettyName()).'-'.$this->generateUuid(),
            'creationInfo' => [
                'created' => gmdate('Y-m-d\TH:i:s\Z'),
                'creators' => ['Tool: composer-'.Composer::getVersion()],
            ],
            'packages' => $spdxPackages,
            'relationships' => $relationships,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSpdxPackage(PackageInterface $package, string $id): array
    {
        $downloadLocation = 'NOASSERTION';
        if (null !== $package->getDistUrl() && Preg::isMatch('{^[a-z][a-z0-9+.-]*://}i', $package->getDistUrl())) {
            $downloadLocation = Url::sanitize($package->getDistUrl());
        } elseif (null !== $package->getSourceUrl() && in_array($package->getSourceType(), ['git', 'hg', 'svn'], true) && Preg::isMatch('{^[a-z][a-z0-9+.-]*://}i', $package->getSourceUrl())) {
            $downloadLocation = $package->getSourceType().'+'.Url::sanitize($package->getSourceUrl());
            if (null !== $package->getSourceReference()) {
                $downloadLocation .= '@'.$package->getSourceReference();
            }
        }

        $spdxPackage = [
            'name' => $package->getPrettyName(),
            'SPDXID' => $id,
            'versionInfo' => $package->getPrettyVersion(),
            'downloadLocation' => $downloadLocation,
            'filesAnalyzed' => false,
        ];

        if (null !== $package->getDistSha1Checksum() && '' !== $package->getDistSha1Checksum()) {
            $spdxPackage['checksums'] = [['algorithm' => 'SHA1', 'checksumValue' => $package->getDistSha1Checksum()]];
        }

        $spdxPackage['licenseConcluded'] = 'NOASSERTION';
        $spdxPackage['licenseDeclared'] = $this->getLicenseExpression($this->getLicenses($package)) ?? 'NOASSERTION';
        $spdxPackage['copyrightText'] = 'NOASSERTION';
        $spdxPackage['externalRefs'] = [[
            'referenceCategory' => 'PACKAGE-MANAGER',
            'referenceType' => 'purl',
            'referenceLocator' => $this->getPurl($package),
        ]];

        return $spdxPackage;
    }

    /**
     * @param array<string, Link>             $links
     * @param array<string, PackageInterface> $packagesByName
     * @return list<PackageInterface>
     */
    private function resolveLinks(array $links, array $packagesByName): array
    {
        $resolved = [];
        foreach ($links as $link) {
            if (isset($packagesByName[$link->getTarget()])) {
                $package = $packagesByName[$link->getTarget()];
                $resolved[spl_object_hash($package)] = $package;
            }
        }

        return array_values($resolved);
    }

    private function getPurl(PackageInterface $package): string
    {
        $nameParts = array_map('rawurlencode', explode('/', $package->getName(), 2));

        return 'pkg:composer/'.implode('/', $nameParts).'@'.rawurlencode($package->getPrettyVersion());
    }

    /**
     * @return list<string>
     */
    private function getLicenses(PackageInterface $package): array
    {
        return $package instanceof CompletePackageInterface ? array_values($package->getLicense()) : [];
    }

    /**
     * Builds an SPDX license expression, multiple licenses in composer.json being alternatives
     *
     * @param list<string> $licenses
     */
    private function getLicenseExpression(array $licenses): ?string
    {
        if (count($licenses) === 0) {
            return null;
        }

        $expression = count($licenses) === 1 ? $licenses[0] : '('.implode(' OR ', $licenses).')';

        return $this->getSpdxLicenses()->validate($expression) ? $expression : null;
    }

    private function getSpdxLicenses(): SpdxLicenses
    {
        if (null === $this->spdxLicenses) {
            $this->spdxLicenses = new SpdxLicenses();
        }

        return $this->spdxLicenses;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
