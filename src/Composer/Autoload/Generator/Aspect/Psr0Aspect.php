<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;

class Psr0Aspect implements AutoloadGeneratorAspectInterface
{
    /**
     * @var array
     *   Array of PSR-0 namespaces and directories, as collected from various composer.json files.
     */
    protected $psr0;

    /**
     * @param array $psr0
     */
    public function __construct(array $psr0)
    {
        $this->psr0 = $psr0;
    }

    /**
     * Generates the autoload_namespaces.php, typically in (project root)/vendor/composer/autoload_namespaces.php.
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register these PSR-0 directories to the
     * class loader.
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task)
    {
        $namespacesCode = '';
        foreach ($this->psr0 as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $task->helper->getPathCode($task, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $namespacesCode .= "    $exportedPrefix => ";
            $namespacesCode .= "array(".implode(', ', $exportedPaths)."),\n";
        }

        $task->helper->dumpArrayFile($task, 'autoload_namespaces.php', $namespacesCode);

        return <<<'PSR0'

        $map = require __DIR__ . '/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }

PSR0;
    }
}
