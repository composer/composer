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

namespace Composer;

use Composer\Json\JsonFile;
use Composer\CaBundle\CaBundle;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Finder\Finder;
use Seld\PharUtils\Timestamps;
use Seld\PharUtils\Linter;

/**
 * The Compiler class compiles composer into a phar
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Compiler
{
    /** @var string */
    private $version;
    /** @var string */
    private $branchAliasVersion = '';
    /** @var \DateTime */
    private $versionDate;

    /**
     * Compiles composer into a single phar file
     *
     * @param string $pharFile The full path to the file to create
     *
     * @throws \RuntimeException
     */
    public function compile(string $pharFile = 'composer.phar'): void
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $process = new ProcessExecutor();

        if (0 !== $process->execute(['git', 'log', '--pretty=%H', '-n1', 'HEAD'], $output, dirname(__DIR__, 2))) {
            throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }
        $this->version = trim($output);

        if (0 !== $process->execute(['git', 'log', '-n1', '--pretty=%ci', 'HEAD'], $output, dirname(__DIR__, 2))) {
            throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }

        $this->versionDate = new \DateTime(trim($output));
        $this->versionDate->setTimezone(new \DateTimeZone('UTC'));

        if (0 === $process->execute(['git', 'describe', '--tags', '--exact-match', 'HEAD'], $output, dirname(__DIR__, 2))) {
            $this->version = trim($output);
        } else {
            // get branch-alias defined in composer.json for dev-main (if any)
            $localConfig = __DIR__.'/../../composer.json';
            $file = new JsonFile($localConfig);
            $localConfig = $file->read();
            if (isset($localConfig['extra']['branch-alias']['dev-main'])) {
                $this->branchAliasVersion = $localConfig['extra']['branch-alias']['dev-main'];
            }
        }

        if ('' === $this->version) {
            throw new \UnexpectedValueException('Version detection failed');
        }

        $phar = new \Phar($pharFile, 0, 'composer.phar');
        $phar->setSignatureAlgorithm(\Phar::SHA512);

        $phar->startBuffering();

        $finderSort = static function ($a, $b): int {
            return strcmp(strtr($a->getRealPath(), '\\', '/'), strtr($b->getRealPath(), '\\', '/'));
        };

        // Add Composer sources
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->notName('ClassLoader.php')
            ->notName('InstalledVersions.php')
            ->in(__DIR__.'/..')
            ->sort($finderSort)
        ;
        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }
        // Add runtime utilities separately to make sure they retains the docblocks as these will get copied into projects
        $this->addFile($phar, new \SplFileInfo(__DIR__ . '/Autoload/ClassLoader.php'), false);
        $this->addFile($phar, new \SplFileInfo(__DIR__ . '/InstalledVersions.php'), false);

        // Add Composer resources
        $finder = new Finder();
        $finder->files()
            ->in(__DIR__.'/../../res')
            ->sort($finderSort)
        ;
        foreach ($finder as $file) {
            $this->addFile($phar, $file, false);
        }

        // Add vendor files
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->notPath('/\/(composer\.(?:json|lock)|[A-Z]+\.md(?:own)?|\.gitignore|appveyor.yml|phpunit\.xml\.dist|phpstan\.neon\.dist|phpstan-config\.neon|phpstan-baseline\.neon|UPGRADE.*\.(?:md|txt))$/')
            ->notPath('/bin\/(jsonlint|validate-json|simple-phpunit|phpstan|phpstan\.phar)(\.bat)?$/')
            ->notPath('justinrainbow/json-schema/demo/')
            ->notPath('justinrainbow/json-schema/dist/')
            ->notPath('composer/pcre/extension.neon')
            ->notPath('composer/LICENSE')
            ->exclude('Tests')
            ->exclude('tests')
            ->exclude('docs')
            ->in(__DIR__.'/../../vendor/')
            ->sort($finderSort)
        ;

        $extraFiles = [];
        foreach ([
            __DIR__ . '/../../vendor/composer/installed.json',
            __DIR__ . '/../../vendor/composer/spdx-licenses/res/spdx-exceptions.json',
            __DIR__ . '/../../vendor/composer/spdx-licenses/res/spdx-licenses.json',
            CaBundle::getBundledCaBundlePath(),
            __DIR__ . '/../../vendor/symfony/console/Resources/bin/hiddeninput.exe',
            __DIR__ . '/../../vendor/symfony/console/Resources/completion.bash',
        ] as $file) {
            $extraFiles[$file] = realpath($file);
            if (!file_exists($file)) {
                throw new \RuntimeException('Extra file listed is missing from the filesystem: '.$file);
            }
        }
        $unexpectedFiles = [];

        foreach ($finder as $file) {
            if (false !== ($index = array_search($file->getRealPath(), $extraFiles, true))) {
                unset($extraFiles[$index]);
            } elseif (!Preg::isMatch('{(^LICENSE(?:\.txt)?$|\.php$)}', $file->getFilename())) {
                $unexpectedFiles[] = (string) $file;
            }

            if (Preg::isMatch('{\.php[\d.]*$}', $file->getFilename())) {
                $this->addFile($phar, $file);
            } else {
                $this->addFile($phar, $file, false);
            }
        }

        if (count($extraFiles) > 0) {
            throw new \RuntimeException('These files were expected but not added to the phar, they might be excluded or gone from the source package:'.PHP_EOL.var_export($extraFiles, true));
        }
        if (count($unexpectedFiles) > 0) {
            throw new \RuntimeException('These files were unexpectedly added to the phar, make sure they are excluded or listed in $extraFiles:'.PHP_EOL.var_export($unexpectedFiles, true));
        }

        // Add bin/composer
        $this->addComposerBin($phar);

        // Stubs
        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        // disabled for interoperability with systems without gzip ext
        // $phar->compressFiles(\Phar::GZ);

        $this->addFile($phar, new \SplFileInfo(__DIR__.'/../../LICENSE'), false);

        unset($phar);

        // re-sign the phar with reproducible timestamp / signature
        $util = new Timestamps($pharFile);
        $util->updateTimestamps($this->versionDate);
        $util->save($pharFile, \Phar::SHA512);

        Linter::lint($pharFile, [
            'vendor/symfony/console/Attribute/AsCommand.php',
            'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php',
            'vendor/symfony/polyfill-intl-normalizer/bootstrap80.php',
            'vendor/symfony/polyfill-mbstring/bootstrap80.php',
            'vendor/symfony/polyfill-php73/Resources/stubs/JsonException.php',
            'vendor/symfony/service-contracts/Attribute/SubscribedService.php',
        ]);
    }

    private function getRelativeFilePath(\SplFileInfo $file): string
    {
        $realPath = $file->getRealPath();
        $pathPrefix = dirname(__DIR__, 2).DIRECTORY_SEPARATOR;

        $pos = strpos($realPath, $pathPrefix);
        $relativePath = ($pos !== false) ? substr_replace($realPath, '', $pos, strlen($pathPrefix)) : $realPath;

        return strtr($relativePath, '\\', '/');
    }

    private function addFile(\Phar $phar, \SplFileInfo $file, bool $strip = true): void
    {
        $path = $this->getRelativeFilePath($file);
        $content = file_get_contents((string) $file);
        if ($strip) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === $file->getFilename()) {
            $content = "\n".$content."\n";
        }

        if ($path === 'src/Composer/Composer.php') {
            $content = strtr(
                $content,
                [
                    '@package_version@' => $this->version,
                    '@package_branch_alias_version@' => $this->branchAliasVersion,
                    '@release_date@' => $this->versionDate->format('Y-m-d H:i:s'),
                ]
            );
            $content = Preg::replace('{SOURCE_VERSION = \'[^\']+\';}', 'SOURCE_VERSION = \'\';', $content);
        }

        $phar->addFromString($path, $content);
    }

    private function addComposerBin(\Phar $phar): void
    {
        $content = file_get_contents(__DIR__.'/../../bin/composer');
        $content = Preg::replace('{^#!/usr/bin/env php\s*}', '', $content);
        $phar->addFromString('bin/composer', $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param  string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    private function stripWhitespace(string $source): string
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], [T_COMMENT, T_DOC_COMMENT])) {
                $output .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (T_WHITESPACE === $token[0]) {
                // reduce wide spaces
                $whitespace = Preg::replace('{[ \t]+}', ' ', $token[1]);
                // normalize newlines to \n
                $whitespace = Preg::replace('{(?:\r\n|\r|\n)}', "\n", $whitespace);
                // trim leading spaces
                $whitespace = Preg::replace('{\n +}', "\n", $whitespace);
                $output .= $whitespace;
            } else {
                $output .= $token[1];
            }
        }

        return $output;
    }

    private function getStub(): string
    {
        $stub = <<<'EOF'
#!/usr/bin/env php
<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view
 * the license that is located at the bottom of this file.
 */

// Avoid APC causing random fatal errors per https://github.com/composer/composer/issues/264
if (extension_loaded('apc') && filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOLEAN) && filter_var(ini_get('apc.cache_by_default'), FILTER_VALIDATE_BOOLEAN)) {
    if (version_compare(phpversion('apc'), '3.0.12', '>=')) {
        ini_set('apc.cache_by_default', 0);
    } else {
        fwrite(STDERR, 'Warning: APC <= 3.0.12 may cause fatal errors when running composer commands.'.PHP_EOL);
        fwrite(STDERR, 'Update APC, or set apc.enable_cli or apc.cache_by_default to 0 in your php.ini.'.PHP_EOL);
    }
}

if (!class_exists('Phar')) {
    echo 'PHP\'s phar extension is missing. Composer requires it to run. Enable the extension or recompile php without --disable-phar then try again.' . PHP_EOL;
    exit(1);
}

Phar::mapPhar('composer.phar');

EOF;

        // add warning once the phar is older than 60 days
        if (Preg::isMatch('{^[a-f0-9]+$}', $this->version)) {
            $warningTime = ((int) $this->versionDate->format('U')) + 60 * 86400;
            $stub .= "define('COMPOSER_DEV_WARNING_TIME', $warningTime);\n";
        }

        return $stub . <<<'EOF'
require 'phar://composer.phar/bin/composer';

__HALT_COMPILER();
EOF;
    }
}
