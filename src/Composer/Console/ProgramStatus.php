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

namespace Composer\Console;

/**
 * The current program state, independent of its terminal representation.
 */
final class ProgramStatus
{
    public const STATE_IDLE = 'idle';
    public const STATE_WORKING = 'working';
    public const STATE_DONE = 'done';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_ERROR = 'error';
    public const STATE_CLEAR = 'clear';

    private const VALID_STATES = [
        self::STATE_IDLE,
        self::STATE_WORKING,
        self::STATE_DONE,
        self::STATE_BLOCKED,
        self::STATE_ERROR,
        self::STATE_CLEAR,
    ];

    /** @var string */
    private $state;
    /** @var string|null */
    private $message;
    /** @var array<string, array<string, mixed>> */
    private $protocolOptions = [];

    /** @var int|null */
    private $progress;

    public function __construct(string $state, ?string $message = null)
    {
        if (!in_array($state, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException('Unknown program status state: '.$state);
        }

        $this->state = $state;
        $this->message = $message;
    }

    public static function idle(?string $message = null): self
    {
        return new self(self::STATE_IDLE, $message);
    }

    public static function working(?string $message = null): self
    {
        return new self(self::STATE_WORKING, $message);
    }

    public static function done(?string $message = null): self
    {
        return new self(self::STATE_DONE, $message);
    }

    public static function blocked(?string $message = null): self
    {
        return new self(self::STATE_BLOCKED, $message);
    }

    public static function error(?string $message = null): self
    {
        return new self(self::STATE_ERROR, $message);
    }

    public static function clear(?string $message = null): self
    {
        return new self(self::STATE_CLEAR, $message);
    }

    public static function fromExitCode(int $exitCode): self
    {
        // Shell exit codes for SIGHUP, SIGINT, and SIGTERM represent cancellation.
        if (in_array($exitCode, [129, 130, 143], true)) {
            return self::idle();
        }

        return $exitCode === 0 ? self::done() : self::error();
    }

    public function withProgress(?int $progress): self
    {
        if ($progress !== null && ($progress < 0 || $progress > 100 || !in_array($this->state, [self::STATE_WORKING, self::STATE_BLOCKED], true))) {
            throw new \InvalidArgumentException('Progress must be between 0 and 100 for a working or blocked program.');
        }

        $status = clone $this;
        $status->progress = $progress;

        return $status;
    }

    /** @param mixed $value */
    public function withProtocolOption(string $protocol, string $name, $value): self
    {
        $status = clone $this;
        $status->protocolOptions[$protocol][$name] = $value;

        return $status;
    }

    /** @return mixed */
    public function getProtocolOption(string $protocol, string $name)
    {
        return $this->protocolOptions[$protocol][$name] ?? null;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }
}
