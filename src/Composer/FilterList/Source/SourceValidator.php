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

namespace Composer\FilterList\Source;

/**
 * @internal
 * @readonly
 * @final
 */
class SourceValidator
{
    /**
     * @param array<mixed> $source
     */
    public function validate(string $listName, array $source): UrlSource
    {
        if (!isset($source['type'])) {
            throw new \RuntimeException('Source configuration is missing the "type" field.');
        }

        if ($source['type'] === 'url') {
            return $this->validateUrlSource($listName, $source);
        }

        throw new \RuntimeException('Unsupported source type "'.$source['type'].'". Only "url" is currently supported.');
    }

    /**
     * @param array{type: string} $source
     */
    private function validateUrlSource(string $listName, array $source): UrlSource
    {
        if (!isset($source['url']) || !is_string($source['url'])) {
            throw new \RuntimeException('Source configuration is missing a string "url" field.');
        }
        if (str_starts_with($source['url'], 'https://') === false) {
            throw new \RuntimeException('Source URL must start with "https://".');
        }

        return new UrlSource($listName, $source['url']);
    }
}
