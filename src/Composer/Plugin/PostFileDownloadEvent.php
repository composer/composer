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
use Composer\Package\PackageInterface;

/**
 * The post file download event.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PostFileDownloadEvent extends Event
{
    /**
     * @var string
     */
    private $fileName;

    /**
     * @var string|null
     */
    private $checksum;

    /**
     * @var string
     */
    private $url;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var string
     */
    private $type;

    /**
     * Constructor.
     *
     * @param string      $name     The event name
     * @param string|null $fileName The file name
     * @param string|null $checksum The checksum
     * @param string      $url      The processed url
     * @param string      $type     The type (package or metadata).
     * @param mixed       $context  Additional context for the download.
     */
    public function __construct(string $name, ?string $fileName, ?string $checksum, string $url, string $type, $context = null)
    {
        /** @phpstan-ignore instanceof.alwaysFalse, booleanAnd.alwaysFalse */
        if ($context === null && $type instanceof PackageInterface) {
            $context = $type;
            $type = 'package';
            trigger_error('PostFileDownloadEvent::__construct should receive a $type=package and the package object in $context since Composer 2.1.', E_USER_DEPRECATED);
        }

        parent::__construct($name);
        $this->fileName = $fileName;
        $this->checksum = $checksum;
        $this->url = $url;
        $this->context = $context;
        $this->type = $type;
    }

    /**
     * Retrieves the target file name location.
     *
     * If this download is of type metadata, null is returned.
     */
    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    /**
     * Gets the checksum.
     */
    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    /**
     * Gets the processed URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Returns the context of this download, if any.
     *
     * If this download is of type package, the package object is returned. If
     * this download is of type metadata, an array{response: Response, repository: RepositoryInterface} is returned.
     *
     * @return mixed
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Get the package.
     *
     * If this download is of type metadata, null is returned.
     *
     * @return PackageInterface|null The package.
     * @deprecated Use getContext instead
     */
    public function getPackage(): ?PackageInterface
    {
        trigger_error('PostFileDownloadEvent::getPackage is deprecated since Composer 2.1, use getContext instead.', E_USER_DEPRECATED);
        $context = $this->getContext();

        return $context instanceof PackageInterface ? $context : null;
    }

    /**
     * Returns the type of this download (package, metadata).
     */
    public function getType(): string
    {
        return $this->type;
    }
}
