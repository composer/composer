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

namespace Composer\SelfUpdate;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\HttpDownloader;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Versions
{
    /**
     * @var string[]
     * @deprecated use Versions::CHANNELS
     */
    public static $channels = self::CHANNELS;

    public const CHANNELS = ['stable', 'preview', 'snapshot', '1', '2', '2.2'];

    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var Config */
    private $config;
    /** @var string */
    private $channel;
    /** @var array<string, array<int, array{path: string, version: string, min-php: int, eol?: bool, lts?: bool, maintenance?: string, maintenance-until?: string|null}>>|null */
    private $versionsData = null;

    public function __construct(Config $config, HttpDownloader $httpDownloader)
    {
        $this->httpDownloader = $httpDownloader;
        $this->config = $config;
    }

    public function getChannel(): string
    {
        if ($this->channel) {
            return $this->channel;
        }

        $channelFile = $this->config->get('home').'/update-channel';
        if (file_exists($channelFile)) {
            $channel = trim(file_get_contents($channelFile));
            if (in_array($channel, ['stable', 'preview', 'snapshot', '2.2'], true)) {
                return $this->channel = $channel;
            }
        }

        return $this->channel = 'stable';
    }

    public function setChannel(string $channel, ?IOInterface $io = null): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Invalid channel '.$channel.', must be one of: ' . implode(', ', self::CHANNELS));
        }

        $channelFile = $this->config->get('home').'/update-channel';
        $this->channel = $channel;

        // rewrite '2' and '1' channels to stable for future self-updates, but LTS ones like '2.2' remain pinned
        $storedChannel = Preg::isMatch('{^\d+$}D', $channel) ? 'stable' : $channel;
        $previouslyStored = file_exists($channelFile) ? trim((string) file_get_contents($channelFile)) : null;
        file_put_contents($channelFile, $storedChannel.PHP_EOL);

        if ($io !== null && $previouslyStored !== $storedChannel) {
            $io->writeError('Storing "<info>'.$storedChannel.'</info>" as default update channel for the next self-update run.');
        }
    }

    /**
     * @return array{path: string, version: string, min-php: int, eol?: bool, lts?: bool, maintenance?: string, maintenance-until?: string|null}
     */
    public function getLatest(?string $channel = null): array
    {
        $versions = $this->getVersionsData();

        foreach ($versions[$channel ?: $this->getChannel()] as $version) {
            if ($version['min-php'] <= \PHP_VERSION_ID) {
                return $version;
            }
        }

        throw new \UnexpectedValueException('There is no version of Composer available for your PHP version ('.PHP_VERSION.')');
    }

    /**
     * Returns the newest version in the channel that is not installable because it requires a newer PHP
     * version than the one currently running, if any.
     *
     * Versions are listed newest-first and getLatest() returns the first installable one, so the newest
     * PHP-blocked version (if there is one) is the very first entry, sitting before whatever getLatest()
     * resolved to. This lets self-update explain why a user is pinned to an older line.
     *
     * @return array{path: string, version: string, min-php: int, eol?: bool, lts?: bool, maintenance?: string, maintenance-until?: string|null}|null
     */
    public function getNewerPhpBlockedVersion(?string $channel = null): ?array
    {
        $versions = $this->getVersionsData();

        if ($channel === null || $channel === '') {
            $channel = $this->getChannel();
        }

        $newest = $versions[$channel][0] ?? null;
        if ($newest !== null && $newest['min-php'] > \PHP_VERSION_ID) {
            return $newest;
        }

        return null;
    }

    /**
     * Classifies a resolved version entry for self-update maintenance warnings.
     *
     * Returns null for actively-maintained ('bugfix') or security-only versions, and for
     * 'critical-security' versions whose end of life is still more than 6 months away. The fields are
     * optional so a versions response missing them (or an unknown future maintenance status) never
     * produces a warning rather than failing.
     *
     * @param  array{version: string, maintenance?: string, maintenance-until?: string|null, lts?: bool} $entry
     * @return array{type: 'eol'|'critical-security', version: string, until: string|null, lts: bool}|null
     */
    public static function getMaintenanceWarning(array $entry, \DateTimeImmutable $now): ?array
    {
        $maintenance = $entry['maintenance'] ?? null;
        $until = $entry['maintenance-until'] ?? null;
        $lts = $entry['lts'] ?? false;

        if ($maintenance === 'eol') {
            return ['type' => 'eol', 'version' => $entry['version'], 'until' => $until, 'lts' => $lts];
        }

        if ($maintenance === 'critical-security') {
            $parsed = is_string($until) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $until) : false;
            // only warn once end of life is within 6 months, so users are not nagged years in advance
            if ($parsed !== false && $parsed <= $now->modify('+6 months')) {
                return ['type' => 'critical-security', 'version' => $entry['version'], 'until' => $until, 'lts' => $lts];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, array{path: string, version: string, min-php: int, eol?: bool, lts?: bool, maintenance?: string, maintenance-until?: string|null}>>
     */
    private function getVersionsData(): array
    {
        if (null === $this->versionsData) {
            if ($this->config->get('disable-tls') === true) {
                $protocol = 'http';
            } else {
                $protocol = 'https';
            }

            $this->versionsData = $this->httpDownloader->get($protocol . '://getcomposer.org/versions')->decodeJson();
        }

        return $this->versionsData;
    }
}
