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

namespace Composer\Util;

use Composer\Config;
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Url
{
    /**
     * @param  Config $config
     * @param  string $url
     * @param  string $ref
     * @return string the updated URL
     */
    public static function updateDistReference(Config $config, $url, $ref)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === 'api.github.com' || $host === 'github.com' || $host === 'www.github.com') {
            if (Preg::isMatch('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(zip|tar)ball/(.+)$}i', $url, $match)) {
                // update legacy github archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            } elseif (Preg::isMatch('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/archive/.+\.(zip|tar)(?:\.gz)?$}i', $url, $match)) {
                // update current github web archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            } elseif (Preg::isMatch('{^https?://api\.github\.com/repos/([^/]+)/([^/]+)/(zip|tar)ball(?:/.+)?$}i', $url, $match)) {
                // update api archives to the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            }
        } elseif ($host === 'bitbucket.org' || $host === 'www.bitbucket.org') {
            if (Preg::isMatch('{^https?://(?:www\.)?bitbucket\.org/([^/]+)/([^/]+)/get/(.+)\.(zip|tar\.gz|tar\.bz2)$}i', $url, $match)) {
                // update Bitbucket archives to the proper reference
                $url = 'https://bitbucket.org/' . $match[1] . '/'. $match[2] . '/get/' . $ref . '.' . $match[4];
            }
        } elseif ($host === 'gitlab.com' || $host === 'www.gitlab.com') {
            if (Preg::isMatch('{^https?://(?:www\.)?gitlab\.com/api/v[34]/projects/([^/]+)/repository/archive\.(zip|tar\.gz|tar\.bz2|tar)\?sha=.+$}i', $url, $match)) {
                // update Gitlab archives to the proper reference
                $url = 'https://gitlab.com/api/v4/projects/' . $match[1] . '/repository/archive.' . $match[2] . '?sha=' . $ref;
            }
        } elseif (in_array($host, $config->get('github-domains'), true)) {
            $url = Preg::replace('{(/repos/[^/]+/[^/]+/(zip|tar)ball)(?:/.+)?$}i', '$1/'.$ref, $url);
        } elseif (in_array($host, $config->get('gitlab-domains'), true)) {
            $url = Preg::replace('{(/api/v[34]/projects/[^/]+/repository/archive\.(?:zip|tar\.gz|tar\.bz2|tar)\?sha=).+$}i', '${1}'.$ref, $url);
        }

        return $url;
    }

    /**
     * @param  string $url
     * @return string
     */
    public static function getOrigin(Config $config, $url)
    {
        if (0 === strpos($url, 'file://')) {
            return $url;
        }

        $origin = (string) parse_url($url, PHP_URL_HOST);
        if ($port = parse_url($url, PHP_URL_PORT)) {
            $origin .= ':'.$port;
        }

        if (strpos($origin, '.github.com') === (strlen($origin) - 11)) {
            return 'github.com';
        }

        if ($origin === 'repo.packagist.org') {
            return 'packagist.org';
        }

        if ($origin === '') {
            $origin = $url;
        }

        // Gitlab can be installed in a non-root context (i.e. gitlab.com/foo). When downloading archives the originUrl
        // is the host without the path, so we look for the registered gitlab-domains matching the host here
        if (
            is_array($config->get('gitlab-domains'))
            && false === strpos($origin, '/')
            && !in_array($origin, $config->get('gitlab-domains'))
        ) {
            foreach ($config->get('gitlab-domains') as $gitlabDomain) {
                if (0 === strpos($gitlabDomain, $origin)) {
                    return $gitlabDomain;
                }
            }
        }

        return $origin;
    }

    /**
     * @param  string $url
     * @return string
     */
    public static function sanitize($url)
    {
        // GitHub repository rename result in redirect locations containing the access_token as GET parameter
        // e.g. https://api.github.com/repositories/9999999999?access_token=github_token
        $url = Preg::replace('{([&?]access_token=)[^&]+}', '$1***', $url);

        $url = Preg::replaceCallback('{^(?P<prefix>[a-z0-9]+://)?(?P<user>[^:/\s@]+):(?P<password>[^@\s/]+)@}i', function ($m) {
            // if the username looks like a long (12char+) hex string, or a modern github token (e.g. ghp_xxx) we obfuscate that
            if (Preg::isMatch('{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+|github_pat_[a-zA-Z0-9_]+)$}', $m['user'])) {
                return $m['prefix'].'***:***@';
            }

            return $m['prefix'].$m['user'].':***@';
        }, $url);

        return $url;
    }
}
