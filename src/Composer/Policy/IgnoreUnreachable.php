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
    public const SCOPES = ['audit', 'install', 'update'];

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

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     */
    public function forBlockScope(string $blockScope): bool
    {
        return $blockScope === ListPolicyConfig::BLOCK_SCOPE_INSTALL
            ? $this->install
            : $this->update;
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
     * Return a copy with the listed scopes flipped to true; other scopes keep
     * their current value. Requires at least one scope so the caller has to
     * declare which scope they are widening.
     */
    public function with(string ...$scopes): self
    {
        if (count($scopes) === 0) {
            throw new \InvalidArgumentException('At least one scope is required.');
        }

        $audit = $this->audit;
        $install = $this->install;
        $update = $this->update;
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::SCOPES, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown scope "%s". Expected one of %s.', $scope, implode(', ', self::SCOPES)));
            }
            if ($scope === 'audit') {
                $audit = true;
            } elseif ($scope === 'install') {
                $install = true;
            } elseif ($scope === 'update') {
                $update = true;
            }
        }

        return new self($audit, $install, $update);
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
            return new self(true, false, false);
        }

        return self::default();
    }
}
