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

use Composer\Util\RemoteFilesystem;
use Composer\Config;
use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Versions
{
    public static $channels = array('stable', 'preview', 'snapshot', '1', '2');

    private $rfs;
    private $config;
    private $channel;

    public function __construct(Config $config, RemoteFilesystem $rfs)
    {
        $this->rfs = $rfs;
        $this->config = $config;
    }

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

    public function setChannel($channel)
    {
        if (!in_array($channel, self::$channels, true)) {
            throw new \InvalidArgumentException('Invalid channel '.$channel.', must be one of: ' . implode(', ', self::$channels));
        }

        $channelFile = $this->config->get('home').'/update-channel';
        $this->channel = $channel;
        file_put_contents($channelFile, (is_numeric($channel) ? 'stable' : $channel).PHP_EOL);
    }

    public function getLatest($channel = null)
    {
        $protocol = extension_loaded('openssl') ? 'https' : 'http';
        $versions = JsonFile::parseJson($this->rfs->getContents('getcomposer.org', $protocol . '://getcomposer.org/versions', false));

        foreach ($versions[$channel ?: $this->getChannel()] as $version) {
            if ($version['min-php'] <= PHP_VERSION_ID) {
                return $version;
            }
        }

        throw new \LogicException('There is no version of Composer available for your PHP version ('.PHP_VERSION.')');
    }
}
