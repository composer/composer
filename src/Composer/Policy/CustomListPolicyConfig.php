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
     * URL sources for custom lists.
     * @var list<UrlSource>
     */
    public $sources;

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param list<UrlSource> $sources
     * @param self::AUDIT_* $audit
     */
    public function __construct(
        string $name,
        bool $block,
        string $audit,
        array $ignore,
        array $sources
    ) {
        parent::__construct(
            $name,
            $block,
            $audit,
            $ignore
        );

        $this->sources = $sources;
    }

    public function withBlockingDisabled()
    {
        return new static(
            $this->name,
            false,
            $this->audit,
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
            [],
            []
        );
    }
}
