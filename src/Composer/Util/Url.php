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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Url
{
    public static function updateDistReference(Config $config, $url, $ref)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === 'api.github.com' || $host === 'github.com' || $host === 'www.github.com') {
            if (preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(zip|tar)ball/(.+)$}i', $url, $match)) {
                // update legacy github archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            } elseif (preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/archive/.+\.(zip|tar)(?:\.gz)?$}i', $url, $match)) {
                // update current github web archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            } elseif (preg_match('{^https?://api\.github\.com/repos/([^/]+)/([^/]+)/(zip|tar)ball(?:/.+)?$}i', $url, $match)) {
                // update api archives to the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $ref;
            }
        } elseif ($host === 'bitbucket.org' || $host === 'www.bitbucket.org') {
            if (preg_match('{^https?://(?:www\.)?bitbucket\.org/([^/]+)/([^/]+)/get/(.+)\.(zip|tar\.gz|tar\.bz2)$}i', $url, $match)) {
                // update Bitbucket archives to the proper reference
                $url = 'https://bitbucket.org/' . $match[1] . '/'. $match[2] . '/get/' . $ref . '.' . $match[4];
            }
        } elseif ($host === 'gitlab.com' || $host === 'www.gitlab.com') {
            if (preg_match('{^https?://(?:www\.)?gitlab\.com/api/v[34]/projects/([^/]+)/repository/archive\.(zip|tar\.gz|tar\.bz2|tar)\?sha=.+$}i', $url, $match)) {
                // update Gitlab archives to the proper reference
                $url = 'https://gitlab.com/api/v4/projects/' . $match[1] . '/repository/archive.' . $match[2] . '?sha=' . $ref;
            }
        } elseif (in_array($host, $config->get('github-domains'), true)) {
            $url = preg_replace('{(/repos/[^/]+/[^/]+/(zip|tar)ball)(?:/.+)?$}i', '$1/'.$ref, $url);
        } elseif (in_array($host, $config->get('gitlab-domains'), true)) {
            $url = preg_replace('{(/api/v[34]/projects/[^/]+/repository/archive\.(?:zip|tar\.gz|tar\.bz2|tar)\?sha=).+$}i', '${1}'.$ref, $url);
        }

        return $url;
    }
}
