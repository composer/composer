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
    /** @var array<string, array<int, array{path: string, version: string, min-php: int, eol?: true}>>|null */
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
     * @return array{path: string, version: string, min-php: int, eol?: true}
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
     * @return array<string, array<int, array{path: string, version: string, min-php: int, eol?: true}>>
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
