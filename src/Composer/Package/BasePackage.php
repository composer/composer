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

use Composer\Repository\RepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * Base class for packages providing name storage and default match implementation
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
abstract class BasePackage implements PackageInterface
{
    /**
     * @phpstan-var array<non-empty-string, array{description: string, method: Link::TYPE_*}>
     * @internal
     */
    public static $supportedLinkTypes = [
        'require' => ['description' => 'requires', 'method' => Link::TYPE_REQUIRE],
        'conflict' => ['description' => 'conflicts', 'method' => Link::TYPE_CONFLICT],
        'provide' => ['description' => 'provides', 'method' => Link::TYPE_PROVIDE],
        'replace' => ['description' => 'replaces', 'method' => Link::TYPE_REPLACE],
        'require-dev' => ['description' => 'requires (for development)', 'method' => Link::TYPE_DEV_REQUIRE],
    ];

    public const STABILITY_STABLE = 0;
    public const STABILITY_RC = 5;
    public const STABILITY_BETA = 10;
    public const STABILITY_ALPHA = 15;
    public const STABILITY_DEV = 20;

    /** @var array<string, self::STABILITY_*> */
    public static $stabilities = [
        'stable' => self::STABILITY_STABLE,
        'RC' => self::STABILITY_RC,
        'beta' => self::STABILITY_BETA,
        'alpha' => self::STABILITY_ALPHA,
        'dev' => self::STABILITY_DEV,
    ];

    /**
     * READ-ONLY: The package id, public for fast access in dependency solver
     * @var int
     * @internal
     */
    public $id;
    /** @var string */
    protected $name;
    /** @var string */
    protected $prettyName;
    /** @var ?RepositoryInterface */
    protected $repository = null;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param string $name The package's name
     */
    public function __construct(string $name)
    {
        $this->prettyName = $name;
        $this->name = strtolower($name);
        $this->id = -1;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getPrettyName(): string
    {
        return $this->prettyName;
    }

    /**
     * @inheritDoc
     */
    public function getNames($provides = true): array
    {
        $names = [
            $this->getName() => true,
        ];

        if ($provides) {
            foreach ($this->getProvides() as $link) {
                $names[$link->getTarget()] = true;
            }
        }

        foreach ($this->getReplaces() as $link) {
            $names[$link->getTarget()] = true;
        }

        return array_keys($names);
    }

    /**
     * @inheritDoc
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @inheritDoc
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @inheritDoc
     */
    public function setRepository(RepositoryInterface $repository): void
    {
        if ($this->repository && $repository !== $this->repository) {
            throw new \LogicException('A package can only be added to one repository');
        }
        $this->repository = $repository;
    }

    /**
     * @inheritDoc
     */
    public function getRepository(): ?RepositoryInterface
    {
        return $this->repository;
    }

    /**
     * checks if this package is a platform package
     *
     * @return bool
     */
    public function isPlatform(): bool
    {
        return $this->getRepository() instanceof PlatformRepository;
    }

    /**
     * Returns package unique name, constructed from name, version and release type.
     *
     * @return string
     */
    public function getUniqueName(): string
    {
        return $this->getName().'-'.$this->getVersion();
    }

    /**
     * @return bool
     */
    public function equals(PackageInterface $package): bool
    {
        $self = $this;
        if ($this instanceof AliasPackage) {
            $self = $this->getAliasOf();
        }
        if ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        return $package === $self;
    }

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getUniqueName();
    }

    public function getPrettyString(): string
    {
        return $this->getPrettyName().' '.$this->getPrettyVersion();
    }

    /**
     * @inheritDoc
     */
    public function getFullPrettyVersion(bool $truncate = true, int $displayMode = PackageInterface::DISPLAY_SOURCE_REF_IF_DEV): string
    {
        if ($displayMode === PackageInterface::DISPLAY_SOURCE_REF_IF_DEV &&
            (!$this->isDev() || !\in_array($this->getSourceType(), ['hg', 'git']))
        ) {
            return $this->getPrettyVersion();
        }

        switch ($displayMode) {
            case PackageInterface::DISPLAY_SOURCE_REF_IF_DEV:
            case PackageInterface::DISPLAY_SOURCE_REF:
                $reference = $this->getSourceReference();
                break;
            case PackageInterface::DISPLAY_DIST_REF:
                $reference = $this->getDistReference();
                break;
            default:
                throw new \UnexpectedValueException('Display mode '.$displayMode.' is not supported');
        }

        if (null === $reference) {
            return $this->getPrettyVersion();
        }

        // if source reference is a sha1 hash -- truncate
        if ($truncate && \strlen($reference) === 40 && $this->getSourceType() !== 'svn') {
            return $this->getPrettyVersion() . ' ' . substr($reference, 0, 7);
        }

        return $this->getPrettyVersion() . ' ' . $reference;
    }

    /**
     * @return int
     *
     * @phpstan-return self::STABILITY_*
     */
    public function getStabilityPriority(): int
    {
        return self::$stabilities[$this->getStability()];
    }

    public function __clone()
    {
        $this->repository = null;
        $this->id = -1;
    }

    /**
     * Build a regexp from a package name, expanding * globs as required
     *
     * @param  string $allowPattern
     * @param  non-empty-string $wrap         Wrap the cleaned string by the given string
     * @return non-empty-string
     */
    public static function packageNameToRegexp(string $allowPattern, string $wrap = '{^%s$}i'): string
    {
        $cleanedAllowPattern = str_replace('\\*', '.*', preg_quote($allowPattern));

        return sprintf($wrap, $cleanedAllowPattern);
    }

    /**
     * Build a regexp from package names, expanding * globs as required
     *
     * @param string[] $packageNames
     * @param non-empty-string $wrap
     * @return non-empty-string
     */
    public static function packageNamesToRegexp(array $packageNames, string $wrap = '{^(?:%s)$}iD'): string
    {
        $packageNames = array_map(
            function ($packageName): string {
                return BasePackage::packageNameToRegexp($packageName, '%s');
            },
            $packageNames
        );

        return sprintf($wrap, implode('|', $packageNames));
    }
}
