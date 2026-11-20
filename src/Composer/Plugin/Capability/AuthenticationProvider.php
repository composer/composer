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

namespace Composer\Plugin\Capability;

/**
 * Authentication Provider Interface
 *
 * This capability allows plugins to provide authentication credentials for host
 * origins, as an alternative to credentials stored in auth.json files.
 *
 * The credentials must be returned in the same shape Composer uses for its own
 * authentications, with the password acting as an auth scheme marker (for
 * example 'x-oauth-basic' for GitHub, 'oauth2' or 'private-token' for GitLab,
 * or the plain password for HTTP basic auth).
 *
 * This capability will receive an array with 'composer' and 'io' keys as
 * constructor argument. Those contain Composer\Composer and Composer\IO\IOInterface
 * instances. It also contains 'config' and 'plugin' keys containing the
 * Composer\Config instance and the plugin instance that created the capability.
 *
 * @api
 */
interface AuthenticationProvider extends Capability
{
    /**
     * Retrieve the authentication credentials for the given origin.
     *
     * @param string $origin The host origin to retrieve credentials for
     *
     * @return array{username: string|null, password: string|null}|null The credentials, or null when not handled
     */
    public function getAuthentication(string $origin): ?array;
}
