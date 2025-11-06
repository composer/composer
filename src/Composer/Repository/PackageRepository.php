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

namespace Composer\Repository;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;

/**
 * Package repository.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageRepository extends ArrayRepository implements AdvisoryProviderInterface
{
    /** @var mixed[] */
    private $config;

    /** @var mixed[] */
    private $securityAdvisories;

    /**
     * Initializes filesystem repository.
     *
     * @param array{package: mixed[]} $config package definition
     */
    public function __construct(array $config)
    {
        parent::__construct();
        $this->config = $config['package'];

        // make sure we have an array of package definitions
        if (!is_numeric(key($this->config))) {
            $this->config = [$this->config];
        }

        $this->securityAdvisories = $config['security-advisories'] ?? [];
    }

    /**
     * Initializes repository (reads file, or remote address).
     */
    protected function initialize(): void
    {
        parent::initialize();

        $loader = new ValidatingArrayLoader(new ArrayLoader(null, true), true);
        foreach ($this->config as $package) {
            try {
                $package = $loader->load($package);
            } catch (\Exception $e) {
                throw new InvalidRepositoryException('A repository of type "package" contains an invalid package definition: '.$e->getMessage()."\n\nInvalid package definition:\n".json_encode($package));
            }

            $this->addPackage($package);
        }
    }

    public function getRepoName(): string
    {
        return Preg::replace('{^array }', 'package ', parent::getRepoName());
    }

    public function hasSecurityAdvisories(): bool
    {
        return count($this->securityAdvisories) > 0;
    }

    /**
     * @todo not sure if this is a good idea, just helped setting up the test fixtures
     */
    public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
    {
        $parser = new VersionParser();

        $advisories = [];
        foreach ($this->securityAdvisories as $packageName => $packageAdvisories) {
            if (isset($packageConstraintMap[$packageName])) {
                $advisories[$packageName] = array_filter(array_map(function (array $data) use ($packageName, $allowPartialAdvisories, $packageConstraintMap, $parser) {
                    $advisory = PartialSecurityAdvisory::create($packageName, $data, $parser);
                    if (!$allowPartialAdvisories && !$advisory instanceof SecurityAdvisory) {
                        throw new \RuntimeException('Advisory for '.$packageName.' could not be loaded as a full advisory from '.$this->getRepoName() . PHP_EOL . var_export($data, true));
                    }

                    if (!$advisory->affectedVersions->matches($packageConstraintMap[$packageName])) {
                        return null;
                    }

                    return $advisory;
                }, $packageAdvisories));
            }
        }

        return ['advisories' => $advisories, 'namesFound' => array_keys($advisories)];
    }
}
