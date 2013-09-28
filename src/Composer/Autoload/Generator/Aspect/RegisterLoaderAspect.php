<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;

class RegisterLoaderAspect implements AutoloadGeneratorAspectInterface
{
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
        return <<<REGISTER_LOADER

        \$loader->register(true);

REGISTER_LOADER;
    }
}
