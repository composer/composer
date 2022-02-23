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

namespace Composer\Util\Http;

use Composer\Util\Url;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class RequestProxy
{
    /** @var mixed[] */
    private $contextOptions;
    /** @var bool */
    private $isSecure;
    /** @var string */
    private $formattedUrl;
    /** @var string */
    private $url;

    /**
     * @param string  $url
     * @param mixed[] $contextOptions
     * @param string  $formattedUrl
     */
    public function __construct(string $url, array $contextOptions, string $formattedUrl)
    {
        $this->url = $url;
        $this->contextOptions = $contextOptions;
        $this->formattedUrl = $formattedUrl;
        $this->isSecure = 0 === strpos($url, 'https://');
    }

    /**
     * Returns an array of context options
     *
     * @return mixed[]
     */
    public function getContextOptions(): array
    {
        return $this->contextOptions;
    }

    /**
     * Returns the safe proxy url from the last request
     *
     * @param  string|null $format Output format specifier
     * @return string      Safe proxy, no proxy or empty
     */
    public function getFormattedUrl(?string $format = ''): string
    {
        $result = '';
        if ($this->formattedUrl) {
            $format = $format ?: '%s';
            $result = sprintf($format, $this->formattedUrl);
        }

        return $result;
    }

    /**
     * Returns the proxy url
     *
     * @return string Proxy url or empty
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Returns true if this is a secure-proxy
     *
     * @return bool False if not secure or there is no proxy
     */
    public function isSecure(): bool
    {
        return $this->isSecure;
    }
}
