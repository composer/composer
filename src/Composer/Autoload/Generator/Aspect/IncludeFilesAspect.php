<?php

namespace Composer\Autoload\Generator\Aspect;

use Composer\Autoload\Generator\AutoloadGenerationTask;
use Composer\Autoload\Generator\Aspect\AutoloadGeneratorAspectInterface;

class IncludeFilesAspect implements AutoloadGeneratorAspectInterface
{
    /**
     * @var array
     */
    protected $files;

    /**
     * @param array $files
     */
    public function __construct(array $files)
    {
        $this->files = $files;
    }

    /**
     * Generates the autoload_files.php, typically in (project root)/vendor/composer/autoload_files.php
     * Returns the PHP code snippet for AutoloaderInit::getLoader() that will include these files..
     *
     * @param AutoloadGenerationTask $task
     *   Information about the current autoload generation task.
     * @return string
     *   PHP snippet for AutoloaderInit::getLoader().
     */
    public function dumpAndGetSnippet(AutoloadGenerationTask $task)
    {
        $filesCode = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($this->files));
        foreach ($files as $functionFile) {
            $filesCode .= '    ' . $task->helper->getPathCode($task, $functionFile) . ",\n";
        }

        if (!$filesCode) {
            return '';
        }

        $task->helper->dumpArrayFile($task, 'autoload_files.php', $filesCode, '');

        return <<<INCLUDE_FILES

        foreach (require __DIR__ . '/autoload_files.php' as \$file) {
            require \$file;
        }

INCLUDE_FILES;
    }
}