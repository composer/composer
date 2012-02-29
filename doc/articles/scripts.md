<!--
    tagline: Script are callbacks that are called before/after installing packages
-->
# Scripts

## What is a script?

A script is a callback (defined as a static method) that will be called
when the event it listens on is triggered.

**Scripts are only executed on the root package, not on the dependencies
that are installed.**


## Event types

- **pre-install-cmd**: occurs before the install command is executed.
- **post-install-cmd**: occurs after the install command is executed.
- **pre-update-cmd**: occurs before the update command is executed.
- **post-update-cmd**: occurs after the update command is executed.
- **pre-package-install**: occurs before a package is installed.
- **post-package-install**: occurs after a package is installed.
- **pre-package-update**: occurs before a package is updated.
- **post-package-update**: occurs after a package is updated.
- **pre-package-uninstall**: occurs before a package has been uninstalled.
- **post-package-uninstall**: occurs after a package has been uninstalled.


## Defining scripts

Scripts are defined by adding the `scripts` key to a project's `composer.json`.

They are specified as an array of classes and static method names.

The classes used as scripts must be autoloadable via Composer's autoload
functionality.

Script definition example:

    {
        "scripts": {
            "post-update-cmd": "MyVendor\\MyClass::postUpdate",
            "post-package-install": ["MyVendor\\MyClass::postPackageInstall"]
        }
    }

Script listener example:

    <?php

    namespace MyVendor;

    class MyClass
    {
        public static function postUpdate($event)
        {
            // do stuff
        }

        public static function postPackageInstall($event)
        {
            $installedPackage = $event->getOperation()->getPackage();
            // do stuff
        }
    }
