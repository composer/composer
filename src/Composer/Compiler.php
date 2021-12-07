<?php

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
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
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
     * @return void
     *
     * @throws \RuntimeException
     */
    public function compile($pharFile = 'composer.phar')
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        // TODO in v2.3 always call with an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = new Process(array('git', 'log', '--pretty="%H"', '-n1', 'HEAD'), __DIR__);
        } else {
            // @phpstan-ignore-next-line
            $process = new Process('git log --pretty="%H" -n1 HEAD', __DIR__);
        }
        if ($process->run() != 0) {
            throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }
        $this->version = trim($process->getOutput());

        // TODO in v2.3 always call with an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = new Process(array('git', 'log', '-n1', '--pretty=%ci', 'HEAD'), __DIR__);
        } else {
            // @phpstan-ignore-next-line
            $process = new Process('git log -n1 --pretty=%ci HEAD', __DIR__);
        }
        if ($process->run() != 0) {
            throw new \RuntimeException('Can\'t run git log. You must ensure to run compile from composer git repository clone and that git binary is available.');
        }

        $this->versionDate = new \DateTime(trim($process->getOutput()));
        $this->versionDate->setTimezone(new \DateTimeZone('UTC'));

        // TODO in v2.3 always call with an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = new Process(array('git', 'describe', '--tags', '--exact-match', 'HEAD'), __DIR__);
        } else {
            // @phpstan-ignore-next-line
            $process = new Process('git describe --tags --exact-match HEAD');
        }
        if ($process->run() == 0) {
            $this->version = trim($process->getOutput());
        } else {
            // get branch-alias defined in composer.json for dev-main (if any)
            $localConfig = __DIR__.'/../../composer.json';
            $file = new JsonFile($localConfig);
            $localConfig = $file->read();
            if (isset($localConfig['extra']['branch-alias']['dev-main'])) {
                $this->branchAliasVersion = $localConfig['extra']['branch-alias']['dev-main'];
            }
        }

        $phar = new \Phar($pharFile, 0, 'composer.phar');
        $phar->setSignatureAlgorithm(\Phar::SHA512);

        $phar->startBuffering();

        $finderSort = function ($a, $b) {
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
            ->notPath('/\/(composer\.(json|lock)|[A-Z]+\.md|\.gitignore|appveyor.yml|phpunit\.xml\.dist|phpstan\.neon\.dist|phpstan-config\.neon)$/')
            ->notPath('/bin\/(jsonlint|validate-json|simple-phpunit)(\.bat)?$/')
            ->notPath('symfony/debug/Resources/ext/')
            ->notPath('justinrainbow/json-schema/demo/')
            ->notPath('justinrainbow/json-schema/dist/')
            ->notPath('composer/installed.json')
            ->notPath('composer/LICENSE')
            ->exclude('Tests')
            ->exclude('tests')
            ->exclude('docs')
            ->in(__DIR__.'/../../vendor/')
            ->sort($finderSort)
        ;

        $extraFiles = array(
            realpath(__DIR__ . '/../../vendor/composer/spdx-licenses/res/spdx-exceptions.json'),
            realpath(__DIR__ . '/../../vendor/composer/spdx-licenses/res/spdx-licenses.json'),
            realpath(CaBundle::getBundledCaBundlePath()),
            realpath(__DIR__ . '/../../vendor/symfony/console/Resources/bin/hiddeninput.exe'),
            realpath(__DIR__ . '/../../vendor/symfony/polyfill-mbstring/Resources/mb_convert_variables.php8'),
        );
        $unexpectedFiles = array();

        foreach ($finder as $file) {
            if (in_array(realpath($file), $extraFiles, true)) {
                unset($extraFiles[array_search(realpath($file), $extraFiles, true)]);
            } elseif (!Preg::isMatch('{([/\\\\]LICENSE|\.php)$}', $file)) {
                $unexpectedFiles[] = (string) $file;
            }

            if (Preg::isMatch('{\.php[\d.]*$}', $file)) {
                $this->addFile($phar, $file);
            } else {
                $this->addFile($phar, $file, false);
            }
        }

        if ($extraFiles) {
            throw new \RuntimeException('These files were expected but not added to the phar, they might be excluded or gone from the source package:'.PHP_EOL.implode(PHP_EOL, $extraFiles));
        }
        if ($unexpectedFiles) {
            throw new \RuntimeException('These files were unexpectedly added to the phar, make sure they are excluded or listed in $extraFiles:'.PHP_EOL.implode(PHP_EOL, $unexpectedFiles));
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

        Linter::lint($pharFile);
    }

    /**
     * @param  \SplFileInfo $file
     * @return string
     */
    private function getRelativeFilePath($file)
    {
        $realPath = $file->getRealPath();
        $pathPrefix = dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR;

        $pos = strpos($realPath, $pathPrefix);
        $relativePath = ($pos !== false) ? substr_replace($realPath, '', $pos, strlen($pathPrefix)) : $realPath;

        return strtr($relativePath, '\\', '/');
    }

    /**
     * @param bool $strip
     *
     * @return void
     */
    private function addFile(\Phar $phar, \SplFileInfo $file, $strip = true)
    {
        $path = $this->getRelativeFilePath($file);
        $content = file_get_contents($file);
        if ($strip) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === basename($file)) {
            $content = "\n".$content."\n";
        }

        if ($path === 'src/Composer/Composer.php') {
            $content = strtr(
                $content,
                array(
                    '@package_version@' => $this->version,
                    '@package_branch_alias_version@' => $this->branchAliasVersion,
                    '@release_date@' => $this->versionDate->format('Y-m-d H:i:s'),
                )
            );
            $content = Preg::replace('{SOURCE_VERSION = \'[^\']+\';}', 'SOURCE_VERSION = \'\';', $content);
        }

        $phar->addFromString($path, $content);
    }

    /**
     * @return void
     */
    private function addComposerBin(\Phar $phar)
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
    private function stripWhitespace($source)
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
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

    /**
     * @return string
     */
    private function getStub()
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
