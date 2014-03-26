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
    set_include_path($path. ':./src::./vendor_libs/symfony/src/');
    
// ONLY CALL register_code() if you need to generate include
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
  
}

return $loader;
