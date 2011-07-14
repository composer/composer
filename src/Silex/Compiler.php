<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Process\Process;

/**
 * The Compiler class compiles the Silex framework.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Compiler
{
    protected $version;

    public function compile($pharFile = 'silex.phar')
    {
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $process = new Process('git log --pretty="%h %ci" -n1 HEAD');
        if ($process->run() > 0) {
            throw new \RuntimeException('The git binary cannot be found.');
        }
        $this->version = trim($process->getOutput());

        $phar = new \Phar($pharFile, 0, 'silex.phar');
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in(__DIR__.'/..')
            ->in(__DIR__.'/../../vendor/pimple/lib')
            ->in(__DIR__.'/../../vendor/Symfony/Component/ClassLoader')
            ->in(__DIR__.'/../../vendor/Symfony/Component/EventDispatcher')
            ->in(__DIR__.'/../../vendor/Symfony/Component/HttpFoundation')
            ->in(__DIR__.'/../../vendor/Symfony/Component/HttpKernel')
            ->in(__DIR__.'/../../vendor/Symfony/Component/Routing')
            ->in(__DIR__.'/../../vendor/Symfony/Component/BrowserKit')
            ->in(__DIR__.'/../../vendor/Symfony/Component/CssSelector')
            ->in(__DIR__.'/../../vendor/Symfony/Component/DomCrawler')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file);
        }

        $this->addFile($phar, new \SplFileInfo(__DIR__.'/../../LICENSE'), false);
        $this->addFile($phar, new \SplFileInfo(__DIR__.'/../../autoload.php'));

        // Stubs
        $phar->setStub($this->getStub());

        $phar->stopBuffering();

        // $phar->compressFiles(\Phar::GZ);

        unset($phar);
    }

    protected function addFile($phar, $file, $strip = true)
    {
        $path = str_replace(dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR, '', $file->getRealPath());
        $content = file_get_contents($file);
        if ($strip) {
            $content = Kernel::stripComments($content);
        }

        $content = str_replace('@package_version@', $this->version, $content);

        $phar->addFromString($path, $content);
    }

    protected function getStub()
    {
        return <<<'EOF'
<?php
/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

Phar::mapPhar('silex.phar');

require_once 'phar://silex.phar/autoload.php';

if ('cli' === php_sapi_name() && isset($argv[1])) {
    switch ($argv[1]) {
        case 'update':
            $remoteFilename = 'http://silex-project.org/get/silex.phar';
            $localFilename = __DIR__.'/silex.phar';

            file_put_contents($localFilename, file_get_contents($remoteFilename));
            break;

        case 'check':
            $latest = trim(file_get_contents('http://silex-project.org/get/version'));

            if ($latest != Silex\Application::VERSION) {
                printf("A newer Silex version is available (%s).\n", $latest);
            } else {
                print("You are using the latest Silex version.\n");
            }
            break;

        case 'version':
            printf("Silex version %s\n", Silex\Application::VERSION);
            break;

        default:
            printf("Unkown command '%s' (available commands: version, check, and update).\n", $argv[1]);
    }

    exit(0);
}

__HALT_COMPILER();
EOF;
    }
}
