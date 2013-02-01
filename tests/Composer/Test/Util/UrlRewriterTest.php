<?php
namespace Composer\Test\Util;

use Composer\Util\UrlRewriter;

class UrlRewriterTest extends \PHPUnit_Framework_TestCase
{
    public function urlProvider()
    {
        return array(
            array('https://example.org/path', array('^https://(.*)' => 'http://$1'), 'http://example.org/path'),
            array('https://example.org/path', array('^https://([^/\s]+)(.*)' => 'http://proxy:8080/$1/https$2'), 'http://proxy:8080/example.org/https/path'),
            array('https://example.org/path', array('^https://(.*)' => 'http://$1', '^(.*)//example.org/(.*)' => '$1//example.com/$2'), 'http://example.com/path'),
            array('http://example.org/path', array('^unknown$' => ''), 'http://example.org/path'),
            array('http://example.org/path', array('^(.*//)(?<!www\.)(.+)' => '$1www.$2'), 'http://www.example.org/path'),
        );
    }

    /**
     * @dataProvider urlProvider
     */
    public function testRewrite($url, array $rules, $expect)
    {
        $rewriter = new UrlRewriter($rules);

        $this->assertEquals($expect, $rewriter->rewrite($url));
    }
}
