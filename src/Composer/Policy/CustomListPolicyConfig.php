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

use Composer\FilterList\Source\UrlSource;

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
            $ignore,
            false
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
}
