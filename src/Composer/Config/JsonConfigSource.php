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

namespace Composer\Config;

use Composer\Json\JsonManipulator;
use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonConfigSource implements ConfigSourceInterface
{
    private $file;
    private $manipulator;

    public function __construct(JsonFile $file)
    {
        $this->file = $file;
    }

    public function addRepository($name, $config)
    {
        $this->manipulateJson('addRepository', $name, $config, function (&$config, $repo, $repoConfig) {
            $config['repositories'][$repo] = $repoConfig;
        });
    }

    public function removeRepository($name)
    {
        $this->manipulateJson('removeRepository', $name, function (&$config, $repo) {
            unset($config['repositories'][$repo]);
        });
    }

    public function addConfigSetting($name, $value)
    {
        $this->manipulateJson('addConfigSetting', $name, $value, function (&$config, $key, $val) {
            $config['config'][$key] = $val;
        });
    }

    public function removeConfigSetting($name)
    {
        $this->manipulateJson('removeConfigSetting', $name, function (&$config, $key) {
            unset($config['config'][$key]);
        });
    }

    protected function manipulateJson($method, $args, $fallback)
    {
        $args = func_get_args();
        // remove method & fallback
        array_shift($args);
        $fallback = array_pop($args);

        if ($this->file->exists()) {
            $contents = file_get_contents($this->file->getPath());
        } else {
            $contents = "{\n    \"config\": {\n    }\n}\n";
        }
        $manipulator = new JsonManipulator($contents);

        // try to update cleanly
        if (call_user_func_array(array($manipulator, $method), $args)) {
            file_put_contents($this->file->getPath(), $manipulator->getContents());
        } else {
            // on failed clean update, call the fallback and rewrite the whole file
            $config = $this->file->read();
            array_unshift($args, $config);
            call_user_func_array($fallback, $args);
            $this->file->write($config);
        }
    }
}
