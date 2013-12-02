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

/**
 * @author Zach Garwood <zachgarwood@gmail.com>
 */
class Setting
{
    const BIN_DIR = 'bin-dir';
    const CACHE_DIR = 'cache-dir';
    const CACHE_FILES_DIR = 'cache-files-dir';
    const CACHE_FILES_MAXSIZE = 'cache-files-maxsize';
    const CACHE_FILES_TTL = 'cache-files-ttl';
    const CACHE_REPO_DIR = 'cache-repo-dir';
    const CACHE_TTL = 'cache-ttl';
    const CACHE_VCS_DIR = 'cache-vcs-dir';
    const DISCARD_CHANGES = 'discard-changes';
    const GITHUB_DOMAINS = 'github-domains';
    const GITHUB_PROTOCOLS = 'github-protocols';
    const MINIMUM_STABILITY = 'minimumn-stability';
    const NOTIFY_ON_INSTALL = 'notify-on-install';
    const PREFER_STABLE = 'prefer-stable';
    const PREFERRED_INSTALL = 'preferred-install';
    const PREPEND_AUTOLOADER = 'prepend-autoloader';
    const PROCESS_TIMEOUT = 'process-timeout';
    const USE_INCLUDE_PATH = 'use-include-path';
    const VENDOR_DIR = 'vendor-dir';
}

