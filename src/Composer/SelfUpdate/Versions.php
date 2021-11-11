<?php

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

use Composer\Util\HttpDownloader;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Versions
{
    /** @var string[] */
    public static $channels = array('stable', 'preview', 'snapshot', '1', '2');

    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var Config */
    private $config;
    /** @var string */
    private $channel;
    /** @var array<string, array<int, array{path: string, version: string, min-php: int}>> */
    private $versionsData;

    public function __construct(Config $config, HttpDownloader $httpDownloader)
    {
        $this->httpDownloader = $httpDownloader;
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getChannel()
    {
        if ($this->channel) {
            return $this->channel;
        }

        $channelFile = $this->config->get('home').'/update-channel';
        if (file_exists($channelFile)) {
            $channel = trim(file_get_contents($channelFile));
            if (in_array($channel, array('stable', 'preview', 'snapshot'), true)) {
                return $this->channel = $channel;
            }
        }

        return $this->channel = 'stable';
    }

    /**
     * @param string $channel
     *
     * @return void
     */
    public function setChannel($channel)
    {
        if (!in_array($channel, self::$channels, true)) {
            throw new \InvalidArgumentException('Invalid channel '.$channel.', must be one of: ' . implode(', ', self::$channels));
        }

        $channelFile = $this->config->get('home').'/update-channel';
        $this->channel = $channel;
        file_put_contents($channelFile, (is_numeric($channel) ? 'stable' : $channel).PHP_EOL);
    }

    /**
     * @param string|null $channel
     *
     * @return array{path: string, version: string, min-php: int}
     */
    public function getLatest($channel = null)
    {
        $versions = $this->getVersionsData();

        foreach ($versions[$channel ?: $this->getChannel()] as $version) {
            if ($version['min-php'] <= PHP_VERSION_ID) {
                return $version;
            }
        }

        throw new \UnexpectedValueException('There is no version of Composer available for your PHP version ('.PHP_VERSION.')');
    }

    /**
     * @return array<string, array<int, array{path: string, version: string, min-php: int}>>
     */
    private function getVersionsData()
    {
        if (!$this->versionsData) {
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
