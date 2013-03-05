# Runtime

Composer includes a runtime that can be used to determine if your package is being managed by Composer.

    // bootstrap.php
    if (class_exists('Composer\\Runtime', false)) {
        // we are being managed by Composer
        return;
    }
    // do autoload stuff
    ...

It is also useful for applications that use the Composer command-line interface.

    $composer = new \Composer\Runtime();
    return $composer->processRun('self-update'));


## Core Methods

The following core methods are available if the `Composer\Runtime` class is found.

* **processRun($params, $capture = false)** Runs the Composer command in `$params`. If `capture` is true, the output is available from `processGetOutput()`. Returns true if the command ran succesfully.

* **processGetCommand()** Returns the command required to run Composer. If composer.phar is installed locally, this will be `php "path/to/project/composer.phar"`, otherwise it will be `composer`.

* **processGetComposerPhar()** Returns the full path to composer.phar

* **processGetOuput($asString = true)** Returns output from a previous `processRun()`, as either a string or an array.

### Additional Methods

The following methods handle any future updates to the runtime.

* **methodExists($name, $update = false)** Checks if a new method exists in the current runtime or `composer.phar`. If $update is true, calls `self-update` and installs the new runtime.

* **runtimeUpdate()** Calls `self-update` and installs a newer runtime if found. Returns the runtime version.

* **runtimeVersion()** Returns the version number of the runtime. Each release has a simple version number, incrementing from `1`.

Any new methods added will be listed below.
