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

/**
 * @internal
 * @final
 * @readonly
 */
class IgnoreUnreachable
{
    /** @var bool */
    public $audit;

    /** @var bool */
    public $install;

    /** @var bool */
    public $update;

    public function __construct(bool $audit, bool $install, bool $update)
    {
        $this->audit = $audit;
        $this->install = $install;
        $this->update = $update;
    }

    public static function default(): self
    {
        return new self(false, true, true);
    }

    public static function all(): self
    {
        return new self(true, true, true);
    }

    public static function none(): self
    {
        return new self(false, false, false);
    }

    /**
     * @param array{ignore-unreachable?: list<string>|bool} $config
     */
    public static function fromRawPolicyConfig(array $config): self
    {
        if (!isset($config['ignore-unreachable'])) {
            return self::default();
        }

        if (is_array($config['ignore-unreachable'])) {
            return new self(
                in_array('audit', $config['ignore-unreachable'], true),
                in_array('install', $config['ignore-unreachable'], true),
                in_array('update', $config['ignore-unreachable'], true)
            );
        }

        return $config['ignore-unreachable'] ? self::all() : self::none();
    }

    /**
     * @param array{ignore-unreachable?: ?bool} $auditConfig
     */
    public static function fromRawAuditConfig(array $auditConfig): self
    {
        if (isset($auditConfig['ignore-unreachable']) && $auditConfig['ignore-unreachable']) {
            return self::all();
        }

        return self::default();
    }
}
