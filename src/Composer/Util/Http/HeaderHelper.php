<?php

namespace Composer\Util\Http;

use Composer\Config;
use Composer\IO\IOInterface;

class HeaderHelper
{
    protected $io;
    protected $config;

    public function __construct(IOInterface $io, Config $config)
    {
        $this->io = $io;
        $this->config = $config;
    }

    public function addHeader(array $headers, $origin, $url)
    {
        $repos = $this->config->getRepositories();
        if (!$repos) {
            return $headers;
        }
        $repoHeaders = $this->getRepoHeaders(, $url);
        if (empty($repoHeaders)) {
            return $headers;
        }
        $repoHeaderNames = array();
        foreach ($repoHeaders as $i => $repoHeader) {
            $pieces = explode(':', $repoHeader);
            $repoHeaderNames[$pieces[0]] = $i;
        }
        $repoHeaderOverrides = array();
        foreach ($headers as $i => $header) {
            $pieces = explode(':', $header);
            $headerName = $pieces[0];
            if (!array_key_exists($headerName, $repoHeaderNames)) {
                continue;
            }
            $headers[$i] = $repoHeaders[$repoHeaderNames[$headerName]];
            $repoHeaderOverrides[] = $repoHeaderNames[$headerName];
        }
        foreach(array_diff(array_keys($repoHeaders), array_values($repoHeaderOverrides)) as $i) {
            $headers[] = $repoHeaders[$i];
        }
        return $headers;
    }

    private function getRepoHeaders(array $repos, $url)
    {
        switch (true) {
            case preg_match('{^http://}i', $url):
                $protocol = 'http';
                break;
            case preg_match('{^https://}i', $url):
                $protocol = 'https';
                break;
            default:
                return array();
        }

        foreach ($repos as $repo) {
            if ($repo['type'] !== 'package') {
                continue;
            }
            if (!array_key_exists('package', $repo)) {
                continue;
            }
            if (
                array_key_exists('dist', $repo['package'])
                && array_key_exists('url', $repo['package']['dist'])
                && $repo['package']['dist']['url'] === $url
                && array_key_exists('options', $repo['package']['dist'])
                && array_key_exists($protocol, $repo['package']['dist']['options'])
                && array_key_exists('header', $repo['package']['dist']['options'][$protocol])
            ) {
                return $repo['package']['dist']['options'][$protocol]['header'];
            }
            if (
                array_key_exists('source', $repo['package'])
                && array_key_exists('url', $repo['package']['source'])
                && $repo['package']['source']['url'] === $url
                && array_key_exists($protocol, $repo['package']['source'])
                && array_key_exists('headers', $repo['package']['source'][$protocol])
            ) {
                return $repo['package']['source'][$protocol]['headers'];
            }
        }
        return array();
    }
}
