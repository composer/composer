<!--
    tagline: Modify the way certain types of packages are installed
-->

# Setting up and using custom installers

## Synopsis

At times it may be necessary for a package to require additional actions during
installation, such as installing packages outside of the default `vendor`
library.

In these cases you could consider creating a Custom Installer to handle your
specific logic.

## Calling a Custom Installer

Suppose that your project already has a Custom Installer for specific modules
then invoking that installer is a matter of defining the correct [type][1] in
your package file.

> _See the next chapter for an instruction how to create Custom Installers._

Every Custom Installer defines which [type][1] string it will recognize. Once
recognized it will completely override the default installer and only apply its
own logic.

An example use-case would be:

> phpDocumentor features Templates that need to be installed outside of the
> default /vendor folder structure. As such they have chosen to adopt the
> `phpdocumentor-template` [type][1] and create a plugin providing the Custom
> Installer to send these templates to the correct folder.

An example composer.json of such a template package would be:

```json
{
    "name": "phpdocumentor/template-responsive",
    "type": "phpdocumentor-template",
    "require": {
        "phpdocumentor/template-installer-plugin": "*"
    }
}
```

> **IMPORTANT**: to make sure that the template installer is present at the
> time the template package is installed, template packages should require
> the plugin package.

## Creating an Installer

A Custom Installer is defined as a class that implements the
[`Composer\Installer\InstallerInterface`][4] and is usually distributed in a
Composer Plugin.

A basic Installer Plugin would thus compose of three files:

1. the package file: composer.json
2. The Plugin class, e.g.: `My\Project\Composer\Plugin.php`, containing a class that implements `Composer\Plugin\PluginInterface`.
3. The Installer class, e.g.: `My\Project\Composer\Installer.php`, containing a class that implements `Composer\Installer\InstallerInterface`.

### composer.json

The package file is the same as any other package file but with the following
requirements:

1. the [type][1] attribute must be `composer-plugin`.
2. the [extra][2] attribute must contain an element `class` defining the
   class name of the plugin (including namespace). If a package contains
   multiple plugins this can be array of class names.

Example:

```json
{
    "name": "phpdocumentor/template-installer-plugin",
    "type": "composer-plugin",
    "license": "MIT",
    "autoload": {
        "psr-0": {"phpDocumentor\\Composer": "src/"}
    },
    "extra": {
        "class": "phpDocumentor\\Composer\\TemplateInstallerPlugin"
    },
    "require": {
        "composer-plugin-api": "1.0.0"
    }
}
```

### The Plugin class

The class defining the Composer plugin must implement the
[`Composer\Plugin\PluginInterface`][3]. It can then register the Custom
Installer in its `activate()` method.

The class may be placed in any location and have any name, as long as it is
autoloadable and matches the `extra.class` element in the package definition.

Example:

```php
<?php

namespace phpDocumentor\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class TemplateInstallerPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new TemplateInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}
```

### The Custom Installer class

The class that executes the custom installation should implement the
[`Composer\Installer\InstallerInterface`][4] (or extend another installer that
implements that interface). It defines the [type][1] string as it will be
recognized by packages that will use this installer in the `supports()` method.

> **NOTE**: _choose your [type][1] name carefully, it is recommended to follow
> the format: `vendor-type`_. For example: `phpdocumentor-template`.

The InstallerInterface class defines the following methods (please see the
source for the exact signature):

* **supports()**, here you test whether the passed [type][1] matches the name
  that you declared for this installer (see the example).
* **isInstalled()**, determines whether a supported package is installed or not.
* **install()**, here you can determine the actions that need to be executed
  upon installation.
* **update()**, here you define the behavior that is required when Composer is
  invoked with the update argument.
* **uninstall()**, here you can determine the actions that need to be executed
  when the package needs to be removed.
* **getInstallPath()**, this method should return the location where the
  package is to be installed, _relative from the location of composer.json._

Example:

```php
<?php

namespace phpDocumentor\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class TemplateInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $prefix = substr($package->getPrettyName(), 0, 23);
        if ('phpdocumentor/template-' !== $prefix) {
            throw new \InvalidArgumentException(
                'Unable to install template, phpdocumentor templates '
                .'should always start their package name with '
                .'"phpdocumentor/template-"'
            );
        }

        return 'data/templates/'.substr($package->getPrettyName(), 23);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'phpdocumentor-template' === $packageType;
    }
}
```

The example demonstrates that it is quite simple to extend the
[`Composer\Installer\LibraryInstaller`][5] class to strip a prefix
(`phpdocumentor/template-`) and use the remaining part to assemble a completely
different installation path.

> _Instead of being installed in `/vendor` any package installed using this
> Installer will be put in the `/data/templates/<stripped name>` folder._

[1]: ../04-schema.md#type
[2]: ../04-schema.md#extra
[3]: https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
[4]: https://github.com/composer/composer/blob/master/src/Composer/Installer/InstallerInterface.php
[5]: https://github.com/composer/composer/blob/master/src/Composer/Installer/LibraryInstaller.php
