<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;
use Composer\Package\PackageInterface;

class IncludePathAspect implements AutoloadGeneratorAspectInterface
{
    /**
     * @var array
     */
    protected $packageMap;

    /**
     * @param array $packageMap
     */
    public function __construct(array $packageMap)
    {
        $this->packageMap = $packageMap;
    }

    /**
     * Generates the include_paths.php, typically in (project root)/vendor/composer/include_paths.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will include those files.
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task)
    {
        $includePaths = array();

        foreach ($this->packageMap as $item) {
            /**
             * @var PackageInterface $package
             */
            list($package, $installPath) = $item;

            if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getIncludePaths() as $includePath) {
                $includePath = trim($includePath, '/');
                $includePaths[] = empty($installPath) ? $includePath : $installPath.'/'.$includePath;
            }
        }

        if (!$includePaths) {
            return '';
        }

        $includePathsCode = '';
        foreach ($includePaths as $path) {
            $includePathsCode .= "    " . $task->helper->getPathCode($task, $path) . ",\n";
        }

        $task->helper->dumpArrayFile($task, 'include_paths.php', $includePathsCode);

        return <<<'INCLUDE_PATH'

        $includePaths = require __DIR__ . '/include_paths.php';
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));

INCLUDE_PATH;
    }
}
