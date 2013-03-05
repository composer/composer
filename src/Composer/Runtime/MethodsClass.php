<?php

namespace Composer\Runtime;

/**
* The MethodsClass class provides the runtime methods, which are called by
* the stub Composer\Runtime class, via __call. All methods should return
* either a truthy or a falsey value (but not null as this is returned when
* a method is not found). Method arguments cannot be by reference.
*
* @author John Stevenson <john-stevenson@blueyonder.co.uk>
*/
class MethodsClass
{
    /**
    * Stores any captured output from processRun()
    *
    * @var array
    */
    private $output;

    /**
    * Stores the parent class so we can update its methodClass
    *
    * @var \Composer\Runtime
    */
    private $parent;

    /**
    * Stores the parent directory
    *
    * @var string
    */
    private $dir;

    /**
    * If we have already self-updated
    *
    * @var mixed
    */
    private $updated;

    /**
    * If we have already included a new class
    *
    * @var mixed
    */
    private $included;

    /**
    * Update this when new methods are added
    *
    * @var int
    */
    const VERSION = 1;

    public function __construct($parent, $dir)
    {
       $this->parent = $parent;
       $this->dir = $dir;
    }

    /**
    * Checks if a method exists in this class. If not, checks composer.phar for an
    * updated MethodsClass and overwrites this one if the method is found. If not
    * and $update is true, calls self-update then checks new composer.phar as above.
    *
    * @param string $name The name of the method to check
    * @param mixed $update Whether to call self-update
    */
    public function methodExists($name, $update = false)
    {
        if (!$result = $this->workMethodExists($name)) {
            if (!$result = $this->workMethodsUpdate($name, false)) {
                $result = $update && $this->workRuntimeUpdate($name);
            }
        }

        return $result;
    }

    /**
    * Returns the command for calling the composer CLI. If composer.phar is in
    * the current project directory this will be 'php "full/path/to/composer.phar"',
    * otherwise it will be 'composer'.
    *
    * @return string The command
    */
    public function processGetCommand()
    {
        if ($composerPhar = $this->processGetComposerPhar(false)) {
            $result = 'php '.escapeshellarg($composerPhar);
        } else {
            $result = 'composer';
        }

        return $result;
    }

    /**
    * Searches for composer.phar and returns its full path
    *
    * @param boolean $global Whether to search outside the current project directory
    * @return string The full path to composer.phar
    */
    public function processGetComposerPhar($global = true)
    {
        $composerPhar = false;

        if ($pos = strrpos($this->dir, DIRECTORY_SEPARATOR.'vendor')) {
            $path = substr($this->dir, 0, $pos + 1).'composer.phar';

            if (file_exists($path)) {
                $composerPhar = $path;
            }
        }

        if (!$composerPhar && $global) {

            foreach (explode(PATH_SEPARATOR, getenv('path')) as $path) {
               $path .= '/composer.phar';
               if (file_exists($path)) {
                   $composerPhar = $path;
                   break;
               }
            }

            if (!$composerPhar) {
                $composerPhar = stream_resolve_include_path('composer.phar');
            }
        }

        return $composerPhar;
    }

    /**
    * Runs a composer CLI command and returns the exit code. If capture
    * is true, the output can be obtained by calling processGetOutput().
    *
    * @param string $params The composer params
    * @param boolean $capture Whether to capture the output
    * @return int The exit code
    */
    public function processRun($params, $capture = false)
    {
        $command = $this->processGetCommand().' '.$params;

        if (!$capture) {
            passthru($command, $exitCode);
        } else {
            $this->output = array();
            exec($command, $this->output, $exitCode);
        }

        return $exitCode === 0;
    }

    /**
    * Returns output from last processRun() with $capture = true
    *
    * @param boolean $asString Return either a string or an array
    * @return string|array The captured output
    */
    public function processGetOutput($asString = true)
    {
        if ($asString) {
            $result = implode(PHP_EOL, $this->output);
        } else {
            $result = $this->output;
        }
        $this->output = array();

        return $result;
    }

    /**
    * Updates composer.phar
    * @return int The resulting runtimeVersion
    */
    public function runtimeUpdate()
    {
        $this->workRuntimeUpdate(null);

        return $this->parent->runtimeVersion();
    }

    /**
    * Returns the runtime version
    *
    * @return int
    */
    public function runtimeVersion()
    {
        return $this::VERSION;
    }

    /**
    * Calls self-update then checks composer.phar for an updated MethodsClass.
    *
    * @param string|null $methodName The name of the method to check
    * @return boolean
    */
    protected function workRuntimeUpdate($methodName)
    {
      if (!$this->updated && $this->processRun('self-update')) {
          $this->updated = true;

          return $this->workMethodsUpdate($methodName, true);
       }
    }

    /**
    * Checks if a method exists in either the passed in class or this one.
    *
    * @param string $name The name of the method to check
    * @param Runtime\MethodsClass|null
    * @return boolean
    */
    protected function workMethodExists($name, $class = null)
    {
        return method_exists(is_object($class) ? $class : $this, $name);
    }

    /**
    * Checks composer.phar for an updated MethodsClass and overwrites this one
    *
    * if the $methodName is found or a newer version exists. Sets parent's
    * methodClass property with the new instance so the new method can be called
    * with the existing parent instance.
    *
    * @param string|null $methodName The name of the method to check
    * @param boolean $fromDownload If we have just self-updated
    * @return boolean Only relevant for $methodName
    */
    private function workMethodsUpdate($methodName, $fromDownload)
    {
        $ok = $fromDownload || (!$this->updated && !$this->included);
        if ($ok && ($phar = $this->processGetComposerPhar(true))) {

            try {
                new \Phar($phar, 0);
            } catch (\Exception $e) {

                return false;
            }

            $phar .= '/src/Composer/Runtime/MethodsClass.php';

            if ($php = @file_get_contents('phar://'.$phar)) {
                $meta = stream_get_meta_data($fh = tmpfile());
                $tmpName = $meta['uri'];

                $className = 'MethodsClass'.uniqid();
                fwrite($fh, preg_replace('#class\s+MethodsClass#', 'class '.$className, $php));
                include $tmpName;
                $this->included = true;
                fclose($fh);

                $className = 'Composer\\Runtime\\'.$className;
                if (class_exists($className)) {
                    $instance = new $className($this->parent, $this->dir);
                    $result = false;

                    if ($update = $this::VERSION < $instance::VERSION) {
                        $result = $methodName && $this->workMethodExists($methodName, $instance);
                    }

                    if ($update && @file_put_contents(__FILE__, $php)) {
                        $this->parent->methodsClass = $instance;

                        return $result;
                    }
                }
            }

            return false;
        }
    }
}
