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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class CompleteAliasPackage extends AliasPackage implements CompletePackageInterface
{
    /** @var CompletePackage */
    protected $aliasOf;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param CompletePackage $aliasOf       The package this package is an alias of
     * @param string          $version       The version the alias must report
     * @param string          $prettyVersion The alias's non-normalized version
     */
    public function __construct(CompletePackage $aliasOf, string $version, string $prettyVersion)
    {
        parent::__construct($aliasOf, $version, $prettyVersion);
    }

    /**
     * @return CompletePackage
     */
    public function getAliasOf()
    {
        return $this->aliasOf;
    }

    public function getScripts(): array
    {
        return $this->aliasOf->getScripts();
    }

    public function setScripts(array $scripts): void
    {
        $this->aliasOf->setScripts($scripts);
    }

    public function getRepositories(): array
    {
        return $this->aliasOf->getRepositories();
    }

    public function setRepositories(array $repositories): void
    {
        $this->aliasOf->setRepositories($repositories);
    }

    public function getLicense(): array
    {
        return $this->aliasOf->getLicense();
    }

    public function setLicense(array $license): void
    {
        $this->aliasOf->setLicense($license);
    }

    public function getKeywords(): array
    {
        return $this->aliasOf->getKeywords();
    }

    public function setKeywords(array $keywords): void
    {
        $this->aliasOf->setKeywords($keywords);
    }

    public function getDescription(): ?string
    {
        return $this->aliasOf->getDescription();
    }

    public function setDescription(?string $description): void
    {
        $this->aliasOf->setDescription($description);
    }

    public function getHomepage(): ?string
    {
        return $this->aliasOf->getHomepage();
    }

    public function setHomepage(?string $homepage): void
    {
        $this->aliasOf->setHomepage($homepage);
    }

    public function getAuthors(): array
    {
        return $this->aliasOf->getAuthors();
    }

    public function setAuthors(array $authors): void
    {
        $this->aliasOf->setAuthors($authors);
    }

    public function getSupport(): array
    {
        return $this->aliasOf->getSupport();
    }

    public function setSupport(array $support): void
    {
        $this->aliasOf->setSupport($support);
    }

    public function getFunding(): array
    {
        return $this->aliasOf->getFunding();
    }

    public function setFunding(array $funding): void
    {
        $this->aliasOf->setFunding($funding);
    }

    public function isAbandoned(): bool
    {
        return $this->aliasOf->isAbandoned();
    }

    public function getReplacementPackage(): ?string
    {
        return $this->aliasOf->getReplacementPackage();
    }

    public function setAbandoned($abandoned): void
    {
        $this->aliasOf->setAbandoned($abandoned);
    }

    public function getArchiveName(): ?string
    {
        return $this->aliasOf->getArchiveName();
    }

    public function setArchiveName(?string $name): void
    {
        $this->aliasOf->setArchiveName($name);
    }

    public function getArchiveExcludes(): array
    {
        return $this->aliasOf->getArchiveExcludes();
    }

    public function setArchiveExcludes(array $excludes): void
    {
        $this->aliasOf->setArchiveExcludes($excludes);
    }
}
