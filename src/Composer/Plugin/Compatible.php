<?php


namespace Composer\Plugin;

/**
 * Implement this interface on your plugin class to specify minimum composer version required to work with.
 *
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
interface Compatible
{
    /**
     * @return string
     */
    public function getMinimumComposerVersion();
}
