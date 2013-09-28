<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;

interface AutoloadGeneratorAspectInterface {

    /**
     * Generates the autoload_classmap.php, typically in (project root)/vendor/composer/autoload_classmap.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will register the class map to the class loader.
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task);

}
