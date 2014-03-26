<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

function includeIfExists($file)
{
    return file_exists($file) ? include $file : false;
}

function register_code() {
    /**
       use this if you want to automatically load function
     */
    spl_autoload_register(function ($className) 
    { 
        #    print ("autoload $className\n");
        $className = str_replace("_", "\\", $className);
        $className = ltrim($className, '\\');
        $fileName = '';
        $namespace = '';
        if ($lastNsPos = strripos($className, '\\'))
            {
                $namespace = substr($className, 0, $lastNsPos);
            $className = substr($className, $lastNsPos + 1);
            $fileName = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
            }
        $fileName .= str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php';
        print ("include_once('$fileName');\n");
        include_once $fileName;
    });
}

if ((!$loader = includeIfExists(__DIR__.'/../vendor/autoload.php')) && (!$loader = includeIfExists(__DIR__.'/../../../autoload.php'))) {

    print "going to bootstrap\n";

    $path = get_include_path();
// set the include paths 
    set_include_path($path. ':./src::./vendor_libs/symfony/src/:./vendor_libs/json_schema/src');
    
/**
   here are the includes used in the composer
*/
    include('Symfony/Component/Console/Output/OutputInterface.php');
    include('Symfony/Component/Console/Helper/HelperInterface.php');
    include('Symfony/Component/Console/Input/InputAwareInterface.php');
    include('Symfony/Component/Console/Output/Output.php');
    include('Symfony/Component/Console/Input/InputInterface.php');
    include('Composer/IO/IOInterface.php');
    include('Symfony/Component/Console/Application.php');
    include('Symfony/Component/Console/Helper/Helper.php');
    include('Symfony/Component/Console/Helper/InputAwareHelper.php');
    include('Symfony/Component/Console/Command/Command.php');
    include('Composer/Command/Command.php');
    include('Composer/Package/PackageInterface.php');
    include('Symfony/Component/Console/Formatter/OutputFormatterStyleInterface.php');
    include('Symfony/Component/Console/Formatter/OutputFormatterInterface.php');
    include('Symfony/Component/Console/Output/StreamOutput.php');
    include('Symfony/Component/Console/Output/ConsoleOutputInterface.php');
    include('Symfony/Component/Console/Input/Input.php');
    include('Composer/IO/BaseIO.php');
    include('Composer/Console/Application.php');
    include('Symfony/Component/Console/Descriptor/DescriptorInterface.php');
    include('Symfony/Component/Console/Descriptor/Descriptor.php');
    include('Composer/Util/ErrorHandler.php');
    include('Composer/Composer.php');
    include('Symfony/Component/Console/Helper/HelperSet.php');
    include('Symfony/Component/Console/Helper/FormatterHelper.php');
    include('Symfony/Component/Console/Helper/DialogHelper.php');
    include('Symfony/Component/Console/Helper/ProgressHelper.php');
    include('Symfony/Component/Console/Helper/TableHelper.php');
    include('Symfony/Component/Console/Helper/Table.php');
    include('Symfony/Component/Console/Output/NullOutput.php');
    include('Symfony/Component/Console/Helper/TableStyle.php');
    include('Composer/Command/Helper/DialogHelper.php');
    include('Symfony/Component/Console/Input/InputDefinition.php');
    include('Symfony/Component/Console/Input/InputArgument.php');
    include('Symfony/Component/Console/Input/InputOption.php');
    include('Symfony/Component/Console/Command/HelpCommand.php');
    include('Symfony/Component/Console/Command/ListCommand.php');
    include('Composer/Command/AboutCommand.php');
    include('Composer/Command/ConfigCommand.php');
    include('Composer/Command/DependsCommand.php');
    include('Composer/Command/InitCommand.php');
    include('Composer/Package/BasePackage.php');
    include('Composer/Command/InstallCommand.php');
    include('Composer/Command/CreateProjectCommand.php');
    include('Composer/Command/UpdateCommand.php');
    include('Composer/Command/SearchCommand.php');
    include('Composer/Command/ValidateCommand.php');
    include('Composer/Command/ShowCommand.php');
    include('Composer/Command/RequireCommand.php');
    include('Composer/Command/DumpAutoloadCommand.php');
    include('Composer/Command/StatusCommand.php');
    include('Composer/Command/ArchiveCommand.php');
    include('Composer/Command/DiagnoseCommand.php');
    include('Composer/Command/RunScriptCommand.php');
    include('Composer/Script/ScriptEvents.php');
    include('Composer/Command/LicensesCommand.php');
    include('Composer/Command/GlobalCommand.php');
    include('Composer/Factory.php');
    include('Symfony/Component/Console/Formatter/OutputFormatterStyle.php');
    include('Symfony/Component/Console/Formatter/OutputFormatter.php');
    include('Symfony/Component/Console/Formatter/OutputFormatterStyleStack.php');
    include('Symfony/Component/Console/Output/ConsoleOutput.php');
    include('Symfony/Component/Console/Input/ArgvInput.php');
    include('Composer/IO/ConsoleIO.php');
    include('Symfony/Component/Console/Input/ArrayInput.php');
    include('Symfony/Component/Console/Helper/DescriptorHelper.php');
    include('Symfony/Component/Console/Descriptor/TextDescriptor.php');
    include('Symfony/Component/Console/Descriptor/XmlDescriptor.php');
    include('Symfony/Component/Console/Descriptor/JsonDescriptor.php');
    include('Symfony/Component/Console/Descriptor/MarkdownDescriptor.php');
    include('Symfony/Component/Console/Descriptor/ApplicationDescription.php');


    // deps used on ./bin/composer install
    include_once('Composer/Json/JsonFile.php');
    include_once('Composer/Util/RemoteFilesystem.php');
    include_once('JsonSchema/Constraints/ConstraintInterface.php');
    include_once('JsonSchema/Constraints/Constraint.php');
    include_once('JsonSchema/Validator.php');

include_once('JsonSchema/Constraints/Schema.php');
include_once('JsonSchema/Constraints/Undefined.php');
include_once('JsonSchema/Constraints/Type.php');
include_once('JsonSchema/Constraints/Object.php');
include_once('JsonSchema/Constraints/String.php');
include_once('JsonSchema/Constraints/Format.php');
include_once('JsonSchema/Constraints/Collection.php');
include_once('Composer/Util/StreamContextFactory.php');
include_once('Composer/Config.php');
include_once('Composer/Config/ConfigSourceInterface.php');
include_once('Composer/Config/JsonConfigSource.php');

include_once('Composer/Util/ProcessExecutor.php');
include_once('Composer/EventDispatcher/EventDispatcher.php');
include_once('Composer/Repository/RepositoryManager.php');
include_once('Composer/Repository/RepositoryInterface.php');
include_once('Composer/Repository/WritableRepositoryInterface.php');


include_once('Composer/Repository/ArrayRepository.php');
include_once('Composer/Repository/WritableArrayRepository.php');
include_once('Composer/Repository/FilesystemRepository.php');
include_once('Composer/Repository/InstalledRepositoryInterface.php');
include_once('Composer/Repository/InstalledFilesystemRepository.php');

include_once('Composer/Package/Version/VersionParser.php');
include_once('Composer/Package/Loader/LoaderInterface.php');
include_once('Composer/Package/Loader/ArrayLoader.php');
include_once('Composer/Package/Loader/RootPackageLoader.php');

include_once('Composer/Util/Git.php');
include_once('Symfony/Component/Process/Process.php');
include_once('Symfony/Component/Process/ProcessPipes.php');
include_once('Composer/Package/Package.php');
include_once('Composer/Package/CompletePackageInterface.php');
include_once('Composer/Package/CompletePackage.php');


include_once('Composer/Package/LinkConstraint/LinkConstraintInterface.php');
include_once('Composer/Downloader/DownloaderInterface.php');
include_once('Composer/Downloader/ChangeReportInterface.php');
include_once('Composer/Downloader/FileDownloader.php');

include_once('Symfony/Component/Finder/Adapter/AdapterInterface.php');
//register_code();

include_once('Symfony/Component/Finder/Adapter/AbstractAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AdapterInterface.php');
include_once('Composer/DependencyResolver/Operation/OperationInterface.php');

include_once('Composer/Package/RootPackageInterface.php');
include_once('Composer/Package/LinkConstraint/SpecificConstraint.php');
include_once('Composer/Package/LinkConstraint/LinkConstraintInterface.php');
include_once('Composer/Package/AliasPackage.php');
include_once('Composer/Repository/StreamableRepositoryInterface.php');
include_once('Composer/Downloader/VcsDownloader.php');
include_once('Composer/Downloader/DownloaderInterface.php');
include_once('Composer/Downloader/ChangeReportInterface.php');
include_once('Composer/Downloader/ArchiveDownloader.php');
include_once('Composer/Downloader/FileDownloader.php');
include_once('Symfony/Component/Finder/Adapter/AbstractFindAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AbstractAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AdapterInterface.php');
include_once('Symfony/Component/Finder/Iterator/FilterIterator.php');
include_once('Symfony/Component/Finder/Comparator/Comparator.php');
include_once('Symfony/Component/Finder/Iterator/MultiplePcreFilterIterator.php');
include_once('Symfony/Component/Finder/Expression/ValueInterface.php');
include_once('Composer/Installer/InstallerInterface.php');
include_once('Composer/EventDispatcher/Event.php');
include_once('Composer/Script/Event.php');
include_once('Composer/DependencyResolver/PolicyInterface.php');
include_once('Composer/DependencyResolver/Operation/SolverOperation.php');
include_once('Composer/DependencyResolver/Operation/OperationInterface.php');

include_once('Composer/Package/RootPackage.php');



include_once('Composer/Package/RootPackageInterface.php');
include_once('Composer/Package/LinkConstraint/VersionConstraint.php');
include_once('Composer/Package/LinkConstraint/SpecificConstraint.php');
include_once('Composer/Package/LinkConstraint/LinkConstraintInterface.php');
include_once('Composer/Package/Link.php');
include_once('Composer/Package/LinkConstraint/MultiConstraint.php');
include_once('Composer/Package/RootAliasPackage.php');
include_once('Composer/Package/AliasPackage.php');
include_once('Composer/Repository/ComposerRepository.php');
include_once('Composer/Repository/StreamableRepositoryInterface.php');
include_once('Composer/Cache.php');
include_once('Composer/Util/Filesystem.php');
include_once('Composer/Installer/InstallationManager.php');
include_once('Composer/Downloader/DownloadManager.php');
include_once('Composer/Downloader/GitDownloader.php');
include_once('Composer/Downloader/VcsDownloader.php');
include_once('Composer/Downloader/DownloaderInterface.php');
include_once('Composer/Downloader/ChangeReportInterface.php');
include_once('Composer/Downloader/SvnDownloader.php');
include_once('Composer/Downloader/HgDownloader.php');
include_once('Composer/Downloader/PerforceDownloader.php');
include_once('Composer/Downloader/ZipDownloader.php');
include_once('Composer/Downloader/ArchiveDownloader.php');
include_once('Composer/Downloader/FileDownloader.php');
include_once('Composer/Downloader/RarDownloader.php');
include_once('Composer/Downloader/TarDownloader.php');
include_once('Symfony/Component/Finder/Finder.php');
include_once('Symfony/Component/Finder/Adapter/GnuFindAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AbstractFindAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AbstractAdapter.php');
include_once('Symfony/Component/Finder/Adapter/AdapterInterface.php');
include_once('Symfony/Component/Finder/Shell/Shell.php');
include_once('Symfony/Component/Finder/Adapter/BsdFindAdapter.php');
include_once('Symfony/Component/Finder/Adapter/PhpAdapter.php');
include_once('Symfony/Component/Finder/Iterator/FileTypeFilterIterator.php');
include_once('Symfony/Component/Finder/Iterator/FilterIterator.php');
include_once('Symfony/Component/Finder/Comparator/DateComparator.php');
include_once('Symfony/Component/Finder/Comparator/Comparator.php');
include_once('Symfony/Component/Finder/Iterator/RecursiveDirectoryIterator.php');
include_once('Symfony/Component/Finder/Iterator/ExcludeDirectoryFilterIterator.php');
include_once('Symfony/Component/Finder/Iterator/DateRangeFilterIterator.php');
include_once('Symfony/Component/Finder/Iterator/PathFilterIterator.php');
include_once('Symfony/Component/Finder/Iterator/MultiplePcreFilterIterator.php');
include_once('Symfony/Component/Finder/Expression/Expression.php');
include_once('Symfony/Component/Finder/Expression/ValueInterface.php');
include_once('Symfony/Component/Finder/Expression/Regex.php');
include_once('Composer/Downloader/GzipDownloader.php');
include_once('Composer/Downloader/PharDownloader.php');
include_once('Composer/Autoload/AutoloadGenerator.php');
include_once('Composer/Installer/LibraryInstaller.php');
include_once('Composer/Installer/InstallerInterface.php');
include_once('Composer/Installer/PearInstaller.php');
include_once('Composer/Installer/PluginInstaller.php');
include_once('Composer/Installer/MetapackageInstaller.php');
include_once('Composer/Plugin/PluginManager.php');
include_once('Composer/Package/Locker.php');
include_once('Composer/Package/Dumper/ArrayDumper.php');
include_once('Composer/Plugin/CommandEvent.php');
include_once('Composer/EventDispatcher/Event.php');
include_once('Composer/Plugin/PluginEvents.php');
include_once('Composer/Installer.php');
include_once('Composer/Script/CommandEvent.php');
include_once('Composer/Script/Event.php');
include_once('Composer/Repository/PlatformRepository.php');
include_once('Composer/Repository/InstalledArrayRepository.php');
include_once('Composer/Repository/CompositeRepository.php');
include_once('Composer/Package/LinkConstraint/EmptyConstraint.php');

include_once('Composer/DependencyResolver/DefaultPolicy.php');
include_once('Composer/DependencyResolver/PolicyInterface.php');
include_once('Composer/DependencyResolver/Pool.php');
include_once('Composer/Plugin/PluginInterface.php');
include_once('Composer/DependencyResolver/Request.php');

include_once('Composer/DependencyResolver/Solver.php');
include_once('Composer/DependencyResolver/RuleSetGenerator.php');
include_once('Composer/DependencyResolver/Decisions.php');
include_once('Composer/DependencyResolver/RuleSet.php');
include_once('Composer/DependencyResolver/Rule.php');
include_once('Composer/DependencyResolver/RuleWatchGraph.php');
include_once('Composer/DependencyResolver/RuleSetIterator.php');
include_once('Composer/DependencyResolver/RuleWatchNode.php');
include_once('Composer/DependencyResolver/RuleWatchChain.php');
include_once('Composer/DependencyResolver/Transaction.php');
include_once('Composer/DependencyResolver/Operation/InstallOperation.php');
include_once('Composer/DependencyResolver/Operation/SolverOperation.php');
include_once('Composer/DependencyResolver/Operation/OperationInterface.php');
include_once('Composer/Script/PackageEvent.php');
include_once('Composer/Plugin/PreFileDownloadEvent.php');

#symfony/console suggests installing symfony/event-dispatcher ()
#phpunit/phpunit suggests installing phpunit/php-invoker (>=1.1.0,<1.2.0)
include_once('Composer/Autoload/ClassMapGenerator.php');
include_once('Symfony/Component/Finder/Iterator/FilenameFilterIterator.php');
include_once('Symfony/Component/Finder/SplFileInfo.php');


// ONLY CALL register_code() if you need to generate include

}

return $loader;
