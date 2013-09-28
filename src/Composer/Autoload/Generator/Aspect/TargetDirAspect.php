<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;

class TargetDirAspect implements AutoloadGeneratorAspectInterface, ExtraMethodsAspectInterface
{
    /**
     * @var string
     */
    protected $mainPackageTargetDir;

    /**
     * @var array
     */
    protected $mainPackagePsr0;

    /**
     * @param string $mainPackageTargetDir
     * @param array $mainPackagePsr0
     */
    public function __construct($mainPackageTargetDir, $mainPackagePsr0)
    {
        $this->mainPackageTargetDir = $mainPackageTargetDir;
        $this->mainPackagePsr0 = $mainPackagePsr0;
    }

    /**
     * Returns a PHP code snippet for AutoloaderInit::getLoader().
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task)
    {
        return <<<REGISTER_AUTOLOAD

        spl_autoload_register(array('ComposerAutoloaderInit{$task->suffix}', 'autoload'), true, true);

REGISTER_AUTOLOAD;
    }

    /**
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet to define additional methods in AutoloaderInit.
     */
    public function getExtraMethods(AutoloadGenerationTask $task)
    {
        $levels = count(explode('/', $task->filesystem->normalizePath($this->mainPackageTargetDir)));
        $prefixes = implode(', ', array_map(function ($prefix) {
            return var_export($prefix, true);
        }, array_keys($this->mainPackagePsr0)));

        $baseDirFromTargetDirCode = $task->filesystem->findShortestPathCode($task->targetPath, $task->basePath, true);

        return <<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF;
    }
}
