<!--
    tagline: Script are callbacks that are called before/after installing packages
-->

# Scripts

## What is a script?

A script, in Composer's terms, can either be a PHP callback (defined as a
static method) or any command-line executable command. Scripts are useful
for executing a package's custom code or package-specific commands during
the Composer execution process.

**NOTE: Only scripts defined in the root package's `composer.json` are
executed. If a dependency of the root package specifies its own scripts,
Composer does not execute those additional scripts.**


## Event names

Composer fires the following named events during its execution process:

- **pre-install-cmd**: occurs before the `install` command is executed.
- **post-install-cmd**: occurs after the `install` command is executed.
- **pre-update-cmd**: occurs before the `update` command is executed.
- **post-update-cmd**: occurs after the `update` command is executed.
- **pre-status-cmd**: occurs before the `status` command is executed.
- **post-status-cmd**: occurs after the `status` command is executed.
- **pre-package-install**: occurs before a package is installed.
- **post-package-install**: occurs after a package is installed.
- **pre-package-update**: occurs before a package is updated.
- **post-package-update**: occurs after a package is updated.
- **pre-package-uninstall**: occurs before a package has been uninstalled.
- **post-package-uninstall**: occurs after a package has been uninstalled.
- **pre-autoload-dump**: occurs before the autoloader is dumped, either
  during `install`/`update`, or via the `dump-autoload` command.
- **post-autoload-dump**: occurs after the autoloader is dumped, either
  during `install`/`update`, or via the `dump-autoload` command.
- **post-root-package-install**: occurs after the root package has been
  installed, during the `create-project` command.
- **post-create-project-cmd**: occurs after the `create-project` command is
  executed.

**NOTE: Composer makes no assumptions about the state of your dependencies 
prior to `install` or `update`. Therefore, you should not specify scripts that 
require Composer-managed dependencies in the `pre-update-cmd` or 
`pre-install-cmd` event hooks. If you need to execute scripts prior to 
`install` or `update` please make sure they are self-contained within your 
root package.**

## Defining scripts

The root JSON object in `composer.json` should have a property called
`"scripts"`, which contains pairs of named events and each event's
corresponding scripts. An event's scripts can be defined as either as a string
(only for a single script) or an array (for single or multiple scripts.)

For any given event:

- Scripts execute in the order defined when their corresponding event is fired.
- An array of scripts wired to a single event can contain both PHP callbacks
and command-line executables commands.
- PHP classes containing defined callbacks must be autoloadable via Composer's
autoload functionality.

Script definition example:

    {
        "scripts": {
            "post-update-cmd": "MyVendor\\MyClass::postUpdate",
            "post-package-install": [
                "MyVendor\\MyClass::postPackageInstall"
            ],
            "post-install-cmd": [
                "MyVendor\\MyClass::warmCache",
                "phpunit -c app/"
            ]
        }
    }

Using the previous definition example, here's the class `MyVendor\MyClass`
that might be used to execute the PHP callbacks:

    <?php

    namespace MyVendor;

    use Composer\Script\Event;

    class MyClass
    {
        public static function postUpdate(Event $event)
        {
            $composer = $event->getComposer();
            // do stuff
        }

        public static function postPackageInstall(Event $event)
        {
            $installedPackage = $event->getOperation()->getPackage();
            // do stuff
        }

        public static function warmCache(Event $event)
        {
            // make cache toasty
        }
    }

When an event is fired, Composer's internal event handler receives a
`Composer\Script\Event` object, which is passed as the first argument to your
PHP callback. This `Event` object has getters for other contextual objects:

- `getComposer()`: returns the current instance of `Composer\Composer`
- `getName()`: returns the name of the event being fired as a string
- `getIO()`: returns the current input/output stream which implements
`Composer\IO\IOInterface` for writing to the console

## Running scripts manually

If you would like to run the scripts for an event manually, the syntax is:

    $ composer run-script [--dev] [--no-dev] script

For example `composer run-script post-install-cmd` will run any **post-install-cmd** scripts that have been defined.
