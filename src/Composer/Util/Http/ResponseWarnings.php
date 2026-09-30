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

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Url;

/**
 * Shows the warnings/infos which repositories can send in their responses
 *
 * @internal
 */
final class ResponseWarnings
{
    /**
     * Warnings which were already shown, keyed by host and warning, see output()
     *
     * @var array<string, true>
     */
    private static $shownWarnings = [];

    /**
     * Shows the warnings/infos of a response, unless the same host already showed the same ones
     *
     * @param  mixed $data the decoded response body, expected to be array{warning?: string, info?: string, warning-versions?: string, info-versions?: string, warnings?: array<array{versions: string, message: string}>, infos?: array<array{versions: string, message: string}>}
     *
     * @return bool whether any warning/info was shown, now or before
     */
    public static function output(IOInterface $io, string $url, $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        // the parts of the body which are not shown do not make a warning a different one
        // key by host so callers passing an origin or a repository url share the same entry, see Url::getOrigin
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            $host = $url;
        } elseif (is_int($port = parse_url($url, PHP_URL_PORT))) {
            $host .= ':'.$port;
        }
        if ($host === 'repo.packagist.org') {
            $host = 'packagist.org';
        }
        $shownKey = $host.':'.json_encode(array_intersect_key($data, array_flip(['warning', 'warning-versions', 'info', 'info-versions', 'warnings', 'infos'])));
        if (isset(self::$shownWarnings[$shownKey])) {
            return true;
        }

        $wrote = false;
        $cleanMessage = static function ($msg) use ($io) {
            if (!$io->isDecorated()) {
                $msg = Preg::replace('{'.chr(27).'\\[[;\d]*m}u', '', $msg);
            }

            return $msg;
        };

        // legacy warning/info keys
        foreach (['warning', 'info'] as $type) {
            if (empty($data[$type])) {
                continue;
            }

            if (!empty($data[$type . '-versions'])) {
                $versionParser = new VersionParser();
                $constraint = $versionParser->parseConstraints($data[$type . '-versions']);
                $composer = new Constraint('==', $versionParser->normalize(Composer::getVersion()));
                if (!$constraint->matches($composer)) {
                    continue;
                }
            }

            $io->writeError('<'.$type.'>'.ucfirst($type).' from '.Url::sanitize($url).': '.$cleanMessage($data[$type]).'</'.$type.'>');
            $wrote = true;
        }

        // modern Composer 2.2+ format with support for multiple warning/info messages
        foreach (['warnings', 'infos'] as $key) {
            if (empty($data[$key])) {
                continue;
            }

            $versionParser = new VersionParser();
            foreach ($data[$key] as $spec) {
                $type = substr($key, 0, -1);
                $constraint = $versionParser->parseConstraints($spec['versions']);
                $composer = new Constraint('==', $versionParser->normalize(Composer::getVersion()));
                if (!$constraint->matches($composer)) {
                    continue;
                }

                $io->writeError('<'.$type.'>'.ucfirst($type).' from '.Url::sanitize($url).': '.$cleanMessage($spec['message']).'</'.$type.'>');
                $wrote = true;
            }
        }

        if ($wrote) {
            self::$shownWarnings[$shownKey] = true;
        }

        return $wrote;
    }

    /**
     * Forgets which warnings were shown, so long-running processes can show them again in their next job
     */
    public static function reset(): void
    {
        self::$shownWarnings = [];
    }
}
