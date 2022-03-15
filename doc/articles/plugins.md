<!--
    tagline: Modify and extend Composer's functionality
-->

# Setting up and using plugins

## Synopsis

You may wish to alter or expand Composer's functionality with your own. For
example if your environment poses special requirements on the behaviour of
Composer which do not apply to the majority of its users or if you wish to
accomplish something with Composer in a way that is not desired by most users.

In these cases you could consider creating a plugin to handle your
specific logic.

## Creating a Plugin

A plugin is a regular Composer package which ships its code as part of the
package and may also depend on further packages.

### Plugin Package

The package file is the same as any other package file but with the following
requirements:

1. The [type][1] attribute must be `composer-plugin`.
2. The [extra][2] attribute must contain an element `class` defining the
   class name of the plugin (including namespace). If a package contains
   multiple plugins, this can be an array of class names.
3. You must require the special package called `composer-plugin-api`
   to define which Plugin API versions your plugin is compatible with.
   Requiring this package doesn't actually include any extra dependencies,
   it only specifies which version of the plugin API to use.

> **Note:** When developing a plugin, although not required, it's useful to add
> a require-dev dependency on `composer/composer` to have IDE autocompletion on Composer classes.

The required version of the `composer-plugin-api` follows the same [rules][7]
as a normal package's rules.

The current Composer plugin API version is `2.3.0`.

An example of a valid plugin `composer.json` file (with the autoloading
part omitted and an optional require-dev dependency on `composer/composer` for IDE auto completion):

```json
{
    "name": "my/plugin-package",
    "type": "composer-plugin",
    "require": {
        "composer-plugin-api": "^2.0"
    },
    "require-dev": {
        "composer/composer": "^2.0"
    },
    "extra": {
        "class": "My\\Plugin"
    }
}
```

### Plugin Class

Every plugin has to supply a class which implements the
[`Composer\Plugin\PluginInterface`][3]. The `activate()` method of the plugin
is called after the plugin is loaded and receives an instance of
[`Composer\Composer`][4] as well as an instance of
[`Composer\IO\IOInterface`][5]. Using these two objects all configuration can
be read and all internal objects and state can be manipulated as desired.

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

## Event Handler

Furthermore plugins may implement the
[`Composer\EventDispatcher\EventSubscriberInterface`][6] in order to have its
event handlers automatically registered with the `EventDispatcher` when the
plugin is loaded.

To register a method to an event, implement the method `getSubscribedEvents()`
and have it return an array. The array key must be the
[event name](https://getcomposer.org/doc/articles/scripts.md#event-names)
and the value is the name of the method in this class to be called.

> **Note:** If you don't know which event to listen to, you can run a Composer
> command with the COMPOSER_DEBUG_EVENTS=1 environment variable set, which might
> help you identify what event you are looking for.

```php
public static function getSubscribedEvents()
{
    return array(
        'post-autoload-dump' => 'methodToBeCalled',
        // ^ event name ^         ^ method name ^
    );
}
```

By default, the priority of an event handler is set to 0. The priority can be
changed by attaching a tuple where the first value is the method name, as
before, and the second value is an integer representing the priority.
Higher integers represent higher priorities. Priority 2 is called before
priority 1, etc.

```php
public static function getSubscribedEvents()
{
    return array(
        // Will be called before events with priority 0
        'post-autoload-dump' => array('methodToBeCalled', 1)
    );
}
```

If multiple methods should be called, then an array of tuples can be attached
to each event. The tuples do not need to include the priority. If it is
omitted, it will default to 0.

```php
public static function getSubscribedEvents()
{
    return array(
        'post-autoload-dump' => array(
            array('methodToBeCalled'      ), // Priority defaults to 0
            array('someOtherMethodName', 1), // This fires first
        )
    );
}
```

Here's a complete example:

```php
<?php

namespace Naderman\Composer\AWS;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;

class AwsPlugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents()
    {
        return array(
            PluginEvents::PRE_FILE_DOWNLOAD => array(
                array('onPreFileDownload', 0)
            ),
        );
    }

    public function onPreFileDownload(PreFileDownloadEvent $event)
    {
        $protocol = parse_url($event->getProcessedUrl(), PHP_URL_SCHEME);

        if ($protocol === 's3') {
            // ...
        }
    }
}
```

## Plugin capabilities

Composer defines a standard set of capabilities which may be implemented by plugins.
Their goal is to make the plugin ecosystem more stable as it reduces the need to mess
with [`Composer\Composer`][4]'s internal state, by providing explicit extension points
for common plugin requirements.

Capable Plugins classes must implement the [`Composer\Plugin\Capable`][8] interface
and declare their capabilities in the `getCapabilities()` method.
This method must return an array, with the _key_ as a Composer Capability class name,
and the _value_ as the Plugin's own implementation class name of said Capability:

```php
<?php

namespace My\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;

class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function getCapabilities()
    {
        return array(
            'Composer\Plugin\Capability\CommandProvider' => 'My\Composer\CommandProvider',
        );
    }
}
```

### Command provider

The [`Composer\Plugin\Capability\CommandProvider`][9] capability allows to register
additional commands for Composer:

```php
<?php

namespace My\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return array(new Command);
    }
}

class Command extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('custom-plugin-command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Executing');
    }
}
```

Now the `custom-plugin-command` is available alongside Composer commands.

> _Composer commands are based on the [Symfony Console Component][10]._

## Running plugins manually

Plugins for an event can be run manually by the `run-script` command. This works the same way as
[running scripts manually](scripts.md#running-scripts-manually).

## Using Plugins

Plugin packages are automatically loaded as soon as they are installed and will
be loaded when Composer starts up if they are found in the current project's
list of installed packages. Additionally all plugin packages installed in the
`COMPOSER_HOME` directory using the Composer global command are loaded before
local project plugins are loaded.

> You may pass the `--no-plugins` option to Composer commands to disable all
> installed plugins. This may be particularly helpful if any of the plugins
> causes errors and you wish to update or uninstall it.

## Plugin Helpers

As of Composer 2, due to the fact that DownloaderInterface can sometimes return Promises
and have been split up in more steps than they used to, we provide a [SyncHelper][11]
to make downloading and installing packages easier.

## Plugin Extra Attributes

A few special plugin capabilities can be unlocked using extra attributes in the plugin's composer.json.

### class

[See above](#plugin-package) for an explanation of the class attribute and how it works.

### plugin-modifies-downloads

Some special plugins need to update package download URLs before they get downloaded.

As of Composer 2.0, all packages are downloaded before they get installed. This means
on the first installation, your plugin is not yet installed when the download occurs,
and it does not get a chance to update the URLs on time.

Specifying `{"extra": {"plugin-modifies-downloads": true}}` in your composer.json will
hint to Composer that the plugin should be installed on its own before proceeding with
the rest of the package downloads. This slightly slows down the overall installation
process however, so do not use it in plugins which do not absolutely require it.

### plugin-modifies-install-path

Some special plugins modify the install path of packages.

As of Composer 2.2.9, you can specify `{"extra": {"plugin-modifies-install-path": true}}`
in your composer.json to hint to Composer that the plugin should be activated as soon
as possible to prevent any bad side-effects from Composer assuming packages are installed
in another location than they actually are.

## Plugin Autoloading

Due to plugins being loaded by Composer at runtime, and to ensure that plugins which
depend on other packages can function correctly, a runtime autoloader is created whenever
a plugin is loaded. That autoloader is only configured to load with the plugin dependencies,
so you may not have access to all the packages which are installed.

[1]: ../04-schema.md#type
[2]: ../04-schema.md#extra
[3]: https://github.com/composer/composer/blob/main/src/Composer/Plugin/PluginInterface.php
[4]: https://github.com/composer/composer/blob/main/src/Composer/Composer.php
[5]: https://github.com/composer/composer/blob/main/src/Composer/IO/IOInterface.php
[6]: https://github.com/composer/composer/blob/main/src/Composer/EventDispatcher/EventSubscriberInterface.php
[7]: ../01-basic-usage.md#package-versions
[8]: https://github.com/composer/composer/blob/main/src/Composer/Plugin/Capable.php
[9]: https://github.com/composer/composer/blob/main/src/Composer/Plugin/Capability/CommandProvider.php
[10]: https://symfony.com/doc/current/components/console.html
[11]: https://github.com/composer/composer/blob/main/src/Composer/Util/SyncHelper.php
