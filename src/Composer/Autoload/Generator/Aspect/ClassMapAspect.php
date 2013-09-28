<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\ClassMapGenerator;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;


class ClassMapAspect implements AutoloadGeneratorAspectInterface {

    /**
     * @var array
     */
    protected $classMap = array();

    /**
     * @param string $dir
     */
    public function scanDir($dir) {
        foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
            $this->classMap[$class] = $path;
        }
    }

    /**
     * @param string $namespace
     * @param string $dir
     */
    public function scanPsr0Dir($namespace, $dir) {
        if (!is_dir($dir)) {
            return;
        }
        $whitelist = sprintf(
            '{%s/%s.+(?<!(?<!/)Test\.php)$}',
            preg_quote($dir),
            strpos($namespace, '_') === false ? preg_quote(strtr($namespace, '\\', '/')) : ''
        );
        foreach (ClassMapGenerator::createMap($dir, $whitelist) as $class => $path) {
            if ('' === $namespace || 0 === strpos($class, $namespace)) {
                if (!isset($this->classMap[$class])) {
                    $this->classMap[$class] = $path;
                }
            }
        }
    }

    /**
     * Generates the autoload_classmap.php, typically in (project root)/vendor/composer/autoload_classmap.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register the class map to the class loader.
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task)
    {
        ksort($this->classMap);

        $classmapCode = '';
        foreach ($this->classMap as $class => $path) {
            $pathCode = $task->helper->getPathCode($task, $path);
            $classmapCode .= '    '.var_export($class, true).' => '.$pathCode.",\n";
        }

        $task->helper->dumpArrayFile($task, 'autoload_classmap.php', $classmapCode);

        return <<<'CLASSMAP'

        $classMap = require __DIR__ . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }

CLASSMAP;
    }
}