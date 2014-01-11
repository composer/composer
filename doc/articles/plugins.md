<!--
    tagline: Modify and extend Composer's functionality
-->

# Setting up and using plugins

## Synopsis

You may wish to alter or expand Composer's functionality with your own. For
example if your environment poses special requirements on the behaviour of
Composer which do not apply to the majority of its users or if you wish to
accomplish something with composer in a way that is not desired by most users.

In these cases you could consider creating a plugin to handle your
specific logic.

## Creating a Plugin

A plugin is a regular composer package which ships its code as part of the
package and may also depend on further packages.

### Plugin Package

The package file is the same as any other package file but with the following
requirements:

1. the [type][1] attribute must be `composer-plugin`.
2. the [extra][2] attribute must contain an element `class` defining the
   class name of the plugin (including namespace). If a package contains
   multiple plugins this can be array of class names.

Additionally you must require the special package called `composer-plugin-api`
to define which composer API versions your plugin is compatible with. The
current composer plugin API version is 1.0.0.

For example

    {
        "name": "my/plugin-package",
        "type": "composer-plugin",
        "require": {
            "composer-plugin-api": "1.0.0"
        }
    }

### Plugin Class

Every plugin has to supply a class which implements the
[`Composer\Plugin\PluginInterface`][3]. The `activate()` method of the plugin
is called after the plugin is loaded and receives an instance of
[`Composer\Composer`][4] as well as an instance of
[`Composer\IO\IOInterface`][5]. Using these two objects all configuration can
be read and all internal objects and state can be manipulated as desired.

Example:

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

## Event Handler

Furthermore plugins may implement the
[`Composer\EventDispatcher\EventSubscriberInterface`][6] in order to have its
event handlers automatically registered with the `EventDispatcher` when the
plugin is loaded.

The events available for plugins are:

* **COMMAND**, is called at the beginning of all commands that load plugins.
  It provides you with access to the input and output objects of the program.
* **PRE_FILE_DOWNLOAD**, is triggered before files are downloaded and allows
  you to manipulate the `RemoteFilesystem` object prior to downloading files
  based on the URL to be downloaded.

> A plugin can also subscribe to [script events][7].

Example:

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
                $awsClient = new AwsClient($this->io, $this->composer->getConfig());
                $s3RemoteFilesystem = new S3RemoteFilesystem($this->io, $event->getRemoteFilesystem()->getOptions(), $awsClient);
                $event->setRemoteFilesystem($s3RemoteFilesystem);
            }
        }
    }

## Using Plugins

Plugin packages are automatically loaded as soon as they are installed and will
be loaded when composer starts up if they are found in the current project's
list of installed packages. Additionally all plugin packages installed in the
`COMPOSER_HOME` directory using the composer global command are loaded before
local project plugins are loaded.

> You may pass the `--no-plugins` option to composer commands to disable all
> installed commands. This may be particularly helpful if any of the plugins
> causes errors and you wish to update or uninstall it.

[1]: ../04-schema.md#type
[2]: ../04-schema.md#extra
[3]: https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
[4]: https://github.com/composer/composer/blob/master/src/Composer/Composer.php
[5]: https://github.com/composer/composer/blob/master/src/Composer/IO/IOInterface.php
[6]: https://github.com/composer/composer/blob/master/src/Composer/EventDispatcher/EventSubscriberInterface.php
[7]: ./scripts.md#event-names
