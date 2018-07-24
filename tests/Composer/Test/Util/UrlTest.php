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

namespace Composer\Test\Util;

use Composer\Util\Url;
use PHPUnit\Framework\TestCase;
use Composer\Config;

class UrlTest extends TestCase
{
    /**
     * @dataProvider distRefsProvider
     */
    public function testUpdateDistReference($url, $expectedUrl, $conf = array(), $ref = 'newref')
    {
        $config = new Config();
        $config->merge(array('config' => $conf));

        $this->assertSame($expectedUrl, Url::updateDistReference($config, $url, $ref));
    }

    public static function distRefsProvider()
    {
        return array(
            // github
            array('https://github.com/foo/bar/zipball/abcd',            'https://api.github.com/repos/foo/bar/zipball/newref'),
            array('https://www.github.com/foo/bar/zipball/abcd',        'https://api.github.com/repos/foo/bar/zipball/newref'),
            array('https://github.com/foo/bar/archive/abcd.zip',        'https://api.github.com/repos/foo/bar/zipball/newref'),
            array('https://github.com/foo/bar/archive/abcd.tar.gz',     'https://api.github.com/repos/foo/bar/tarball/newref'),
            array('https://api.github.com/repos/foo/bar/tarball',       'https://api.github.com/repos/foo/bar/tarball/newref'),
            array('https://api.github.com/repos/foo/bar/tarball/abcd',  'https://api.github.com/repos/foo/bar/tarball/newref'),

            // github enterprise
            array('https://mygithub.com/api/v3/repos/foo/bar/tarball/abcd',  'https://mygithub.com/api/v3/repos/foo/bar/tarball/newref', array('github-domains' => array('mygithub.com'))),

            // bitbucket
            array('https://bitbucket.org/foo/bar/get/abcd.zip',         'https://bitbucket.org/foo/bar/get/newref.zip'),
            array('https://www.bitbucket.org/foo/bar/get/abcd.tar.bz2', 'https://bitbucket.org/foo/bar/get/newref.tar.bz2'),

            // gitlab
            array('https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=abcd',       'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=newref'),
            array('https://www.gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=abcd',   'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.zip?sha=newref'),
            array('https://gitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.gz?sha=abcd',    'https://gitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=newref'),

            // gitlab enterprise
            array('https://mygitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=abcd',  'https://mygitlab.com/api/v4/projects/foo%2Fbar/repository/archive.tar.gz?sha=newref', array('gitlab-domains' => array('mygitlab.com'))),
            array('https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=abcd', 'https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=newref', array('gitlab-domains' => array('mygitlab.com'))),
            array('https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=abcd', 'https://mygitlab.com/api/v3/projects/foo%2Fbar/repository/archive.tar.bz2?sha=65', array('gitlab-domains' => array('mygitlab.com')), '65'),
        );
    }
}
