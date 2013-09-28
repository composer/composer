<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;

interface ExtraMethodsAspectInterface {

    /**
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet to define additional methods in AutoloaderInit.
     */
    public function getExtraMethods(AutoloadGenerationTask $task);
}