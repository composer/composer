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

namespace Composer\Policy;

use Composer\FilterList\Source\SourceValidator;
use Composer\FilterList\Source\UrlSource;
use Composer\Semver\VersionParser;

/**
 * @internal
 * @final
 * @readonly
 */
class CustomListPolicyConfig extends ListPolicyConfig
{
    /**
     * @var self::BLOCK_SCOPE_*
     */
    public $blockScope;

    /**
     * URL sources for custom lists.
     * @var list<UrlSource>
     */
    public $sources;

    /**
     * Custom lists opt into install-time pool filtering via the base-class hook,
     * so a `block-scope` of `install` or `all` is honoured during `composer install`.
     * The per-list `block-scope` config field narrows the decision inside shouldBlock().
     */
    protected function supportsInstallBlockScope(): bool
    {
        return true;
    }

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param list<UrlSource> $sources
     * @param self::AUDIT_* $audit
     * @param self::BLOCK_SCOPE_* $blockScope
     */
    public function __construct(
        string $name,
        bool $block,
        string $audit,
        string $blockScope,
        array $ignore,
        array $sources
    ) {
        parent::__construct(
            $name,
            $block,
            $audit,
            $ignore
        );

        $this->blockScope = $blockScope;
        $this->sources = $sources;
    }

    /**
     * @param self::BLOCK_SCOPE_* $blockScope
     */
    public function shouldBlock(string $blockScope): bool
    {
        // Defer the "is this list enabled at all + supports this scope?" question
        // to the base class; only the per-list `block-scope` narrowing is list-specific.
        if (!parent::shouldBlock($blockScope)) {
            return false;
        }

        return $this->blockScope === self::BLOCK_SCOPE_ALL || $this->blockScope === $blockScope;
    }

    public function withBlockingDisabled()
    {
        return new static(
            $this->name,
            false,
            $this->audit,
            $this->blockScope,
            $this->ignore,
            $this->sources
        );
    }

    public function withAudit(string $audit)
    {
        return new static(
            $this->name,
            $this->block,
            $audit,
            $this->blockScope,
            $this->ignore,
            $this->sources
        );
    }

    /**
     * @param array<string, mixed>|bool $listConfig
     */
    public static function fromRawConfig(string $listName, $listConfig, VersionParser $parser): self
    {
        if ($listConfig === false) {
            return self::disabled($listName);
        }

        if ($listConfig === true) {
            $listConfig = [];
        }

        if (!is_array($listConfig)) {
            return self::disabled($listName);
        }

        $sources = [];
        $sourceValidator = new SourceValidator();
        foreach ($listConfig['sources'] ?? [] as $sourceConfig) {
            if (is_array($sourceConfig)) {
                $sources[] = $sourceValidator->validate($listName, $sourceConfig);
            }
        }

        return new self(
            $listName,
            (bool) ($listConfig['block'] ?? true),
            $listConfig['audit'] ?? self::AUDIT_FAIL,
            $listConfig['block-scope'] ?? self::BLOCK_SCOPE_UPDATE,
            IgnorePackageRule::parseIgnoreMap($listConfig['ignore'] ?? [], $parser),
            $sources
        );
    }

    public static function disabled(string $listName): self
    {
        return new static(
            $listName,
            false,
            self::AUDIT_IGNORE,
            self::BLOCK_SCOPE_UPDATE,
            [],
            []
        );
    }
}
