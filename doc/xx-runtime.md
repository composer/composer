# Runtime

Composer includes a runtime that can be used to determine if your package is being managed by Composer.

    // bootstrap.php
    if (class_exists('Composer\\Runtime', false)) {
        // we are being managed by Composer
        return;
    }
    // do autoload stuff
    ...

It is also useful for applications that call the Composer command-line interface.

    $composer = new \Composer\Runtime();
    return $composer->processRun('dump-autoload'));


## Core Methods

The following core methods are available if the `Composer\Runtime` class is found.

* **processRun($params, $capture = false)** Runs the Composer command in `$params`. If `capture` is true, the output is available from `processGetOutput()`. Returns true if the command ran succesfully.

* **processGetCommand()** Returns the command required to run Composer. If composer.phar is installed locally, this will be `php "path/to/project/composer.phar"`, otherwise it will be `composer`.

* **processGetComposerPhar()** Returns the full path to composer.phar

* **processGetOuput($asString = true)** Returns output from a previous `processRun()`, as either a string or an array.

### Update Methods

The following methods handle future updates to the runtime.

* **methodExists($name, $update = false)** Checks if a method exists in the current runtime or `composer.phar`. If $update is true, calls `self-update` and installs a newer runtime if found. Returns true if the method exists.

* **runtimeUpdate()** Calls `self-update` and installs a newer runtime if found. Returns the runtime version `int`.

* **runtimeVersion()** Returns  the runtime version `int`. Uses a simple scheme, incrementing from `1`.

Any new methods added by an update will be listed below.
