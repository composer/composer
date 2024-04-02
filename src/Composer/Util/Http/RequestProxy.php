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
 *
 * @phpstan-import-type contextOptions from \Composer\Util\Http\ProxyItem
 */
class RequestProxy
{
    /** @var ?contextOptions */
    private $contextOptions;
    /** @var bool */
    private $isSecure;
    /** @var ?non-empty-string */
    private $status;
    /** @var ?non-empty-string */
    private $url;

    /**
     * @param ?non-empty-string $url
     * @param ?contextOptions $contextOptions
     * @param ?non-empty-string $status
     */
    public function __construct(?string $url, ?array $contextOptions, ?string $status)
    {
        $this->url = $url;
        $this->contextOptions = $contextOptions;
        $this->status = $status;
        $this->isSecure = 0 === strpos((string) $url, 'https://');
    }

    /**
     * Returns the context options to use for this request, otherwise null
     *
     * @return ?contextOptions
     */
    public function getContextOptions(): ?array
    {
        return $this->contextOptions;
    }

    /**
     * Returns proxy info associated with this request
     *
     * An empty return value means that the user has not set a proxy.
     * A non-empty value will either be the sanitized proxy url if a proxy is
     * required, or a message indicating that a no_proxy value has disabled the
     * proxy.
     *
     * @param ?string $format Output format specifier
     */
    public function getStatus(?string $format = null): string
    {
        if ($this->status === null) {
            return '';
        }

        $format = $format ?? '%s';
        if (strpos($format, '%s') !== false) {
            return sprintf($format, $this->status);
        }

        throw new \InvalidArgumentException('String format specifier is missing');
    }

    /**
     * Returns the proxy url to use for this request, otherwise null
     *
     * @ return ?non-empty-string
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * Returns true if this is a secure (HTTPS) proxy
     *
     * A false value means that this is either an HTTP proxy, or that a proxy
     * is not required for this request, or that the user has not set a proxy.
     */
    public function isSecure(): bool
    {
        return $this->isSecure;
    }
}
