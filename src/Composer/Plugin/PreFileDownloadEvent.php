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

namespace Composer\Plugin;

use Composer\EventDispatcher\Event;
use Composer\Util\HttpDownloader;

/**
 * The pre file download event.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PreFileDownloadEvent extends Event
{
    /**
     * @var HttpDownloader
     */
    private $httpDownloader;

    /**
     * @var non-empty-string
     */
    private $processedUrl;

    /**
     * @var string|null
     */
    private $customCacheKey;

    /**
     * @var string
     */
    private $type;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var mixed[]
     */
    private $transportOptions = [];

    /**
     * Constructor.
     *
     * @param string           $name           The event name
     * @param mixed            $context
     * @param non-empty-string $processedUrl
     */
    public function __construct(string $name, HttpDownloader $httpDownloader, string $processedUrl, string $type, $context = null)
    {
        parent::__construct($name);
        $this->httpDownloader = $httpDownloader;
        $this->processedUrl = $processedUrl;
        $this->type = $type;
        $this->context = $context;
    }

    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader;
    }

    /**
     * Retrieves the processed URL that will be downloaded.
     *
     * @return non-empty-string
     */
    public function getProcessedUrl(): string
    {
        return $this->processedUrl;
    }

    /**
     * Sets the processed URL that will be downloaded.
     *
     * @param non-empty-string $processedUrl New processed URL
     */
    public function setProcessedUrl(string $processedUrl): void
    {
        $this->processedUrl = $processedUrl;
    }

    /**
     * Retrieves a custom package cache key for this download.
     */
    public function getCustomCacheKey(): ?string
    {
        return $this->customCacheKey;
    }

    /**
     * Sets a custom package cache key for this download.
     *
     * @param string|null $customCacheKey New cache key
     */
    public function setCustomCacheKey(?string $customCacheKey): void
    {
        $this->customCacheKey = $customCacheKey;
    }

    /**
     * Returns the type of this download (package, metadata).
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns the context of this download, if any.
     *
     * If this download is of type package, the package object is returned.
     * If the type is metadata, an array{repository: RepositoryInterface} is returned.
     *
     * @return mixed
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Returns transport options for the download.
     *
     * Only available for events with type metadata, for packages set the transport options on the package itself.
     *
     * @return mixed[]
     */
    public function getTransportOptions(): array
    {
        return $this->transportOptions;
    }

    /**
     * Sets transport options for the download.
     *
     * Only available for events with type metadata, for packages set the transport options on the package itself.
     *
     * @param mixed[] $options
     */
    public function setTransportOptions(array $options): void
    {
        $this->transportOptions = $options;
    }
}
