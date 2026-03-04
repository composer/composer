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

namespace Composer\Platform;

use Composer\Pcre\Preg;

class Runtime
{
    /**
     * @param class-string $class
     */
    public function hasConstant(string $constant, ?string $class = null): bool
    {
        return defined(ltrim($class.'::'.$constant, ':'));
    }

    /**
     * @param class-string $class
     *
     * @return mixed
     */
    public function getConstant(string $constant, ?string $class = null)
    {
        return constant(ltrim($class.'::'.$constant, ':'));
    }

    public function hasFunction(string $fn): bool
    {
        return function_exists($fn);
    }

    /**
     * @param mixed[] $arguments
     *
     * @return mixed
     */
    public function invoke(callable $callable, array $arguments = [])
    {
        return $callable(...$arguments);
    }

    /**
     * @param class-string $class
     */
    public function hasClass(string $class): bool
    {
        return class_exists($class, false);
    }

    /**
     * @template T of object
     * @param mixed[] $arguments
     *
     * @phpstan-param class-string<T> $class
     * @phpstan-return T
     *
     * @throws \ReflectionException
     */
    public function construct(string $class, array $arguments = []): object
    {
        if (empty($arguments)) {
            return new $class;
        }

        $refl = new \ReflectionClass($class);

        return $refl->newInstanceArgs($arguments);
    }

    /** @return string[] */
    public function getExtensions(): array
    {
        return get_loaded_extensions();
    }

    public function getExtensionVersion(string $extension): string
    {
        $version = phpversion($extension);
        if ($version === false) {
            $version = '0';
        }

        return $version;
    }

    /**
     * @throws \ReflectionException
     */
    public function getExtensionInfo(string $extension): string
    {
        $reflector = new \ReflectionExtension($extension);

        ob_start();
        $reflector->info();

        $info = (string) ob_get_clean();

        if ('cli' === PHP_SAPI) {
            return $info;
        }

        return self::parseHtmlExtensionInfo($info);
    }

    /**
     * @internal Only public for unit tests
     */
    public static function parseHtmlExtensionInfo(string $html): string
    {
        $result = [];

        if ((bool) Preg::match('~<h2>\s*<a[^>]*>([^<]+)</a>\s*</h2>~i', $html, $matches)) {
            $result[] = trim(html_entity_decode($matches[1]));
            $result[] = '';
        }

        if ((bool) Preg::matchAll('~<tr>\s*<td class="e">\s*(.*?)\s*</td>\s*<td class="v">\s*(.*?)\s*</td>\s*</tr>~is', $html, $matches)) {
            $count = min(\count($matches[1]), \count($matches[2]));

            for ($i = 0; $i < $count; $i++) {
                $key   = trim(html_entity_decode(strip_tags($matches[1][$i])));
                $value = trim(html_entity_decode(strip_tags($matches[2][$i])));
                $result[] = $key . ' => ' . $value;
            }
        }

        return implode("\n", $result);
    }
}
