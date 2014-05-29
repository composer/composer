<?php
namespace Composer\Command;


use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Config\JsonConfigSource;
use Composer\Json\JsonManipulator;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class RepositoriesCommand extends Command
{
    protected $config;
    protected $configFile;
    protected $configSource;
    
    protected function configure()
    {
        $this->setName('repositories')
        ->setDescription('Manage external repositories in project composer.json')
        ->setDefinition(array(
            new InputArgument('action', InputArgument::OPTIONAL, '[add], [remove], [list], or [packagist].'),
            new InputArgument('first', InputArgument::OPTIONAL, 'Depends on action, see below.'),
            new InputArgument('second', InputArgument::OPTIONAL, 'Depends on action, see below.'),
            new InputArgument('third', InputArgument::OPTIONAL, 'Depends on action, see below.'),
        ))
        
        ->setHelp('
The `repositories` command creates a unified command
interface for managing your composer.json\'s repository
configuration. This command allows you add both composer and
vcs repository types to your project, toggle packagist
suport, and view a list of configured repositories for an
existing project.

# Listing Repositories

To view a list of configured repositories, use the `list`
action.  This is also the default action.

    <comment>%command.full_name% repositories</comment>
    <comment>%command.full_name% repositories list</comment>    

# Toggling Packagist Support    

You can toggle support for packagist on and off with the
`packagist` action.  This action accepts a single parameter,
enabled/disabled.

    <comment>%command.full_name% repositories packagist enabled</comment> 
    <comment>%command.full_name% repositories packagist disabled</comment>    
    
# Adding a Repository

The `add` action adds a repository.  It has three arguments:
name, url, and type.  If type is ommited, will default to
`composer`

    <comment>%command.full_name% repositories packagist add [name] [url] [type] </comment> 
    <comment>%command.full_name% repositories packagist add firegento http://packages.firegento.com</comment>    
    <comment>%command.full_name% repositories packagist add firegento http://packages.firegento.com composer</comment>    
    <comment>%command.full_name% repositories packagist add monolog https://github.com/igorw/monolog vcs</comment>    
    
# Removing a Repository

The `remove` action removes a repository.  It has one
argument — the name or URL of the repository.  

    <comment>%command.full_name% repositories packagist remove firegento </comment> 
    <comment>%command.full_name% repositories packagist remove http://packages.firegento.com</comment> 
    
        ');
    }
    
    public function getAllowedActions()
    {
        return array('add','remove','packagist','list');
    }

    public function getAllowedRepositoryTypes()
    {
        return array('composer','vcs');
    }   
    
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $path         = './composer.json';
        
        $this->config       = Factory::createConfig();        
        $this->configFile   = new JsonFile($path);
        $this->configSource = new JsonConfigSource($this->configFile);
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {        
        $action = $input->getArgument('action');
        $action = $action ? $action : 'list';
        if(!in_array($action, $this->getAllowedActions())) {
            $output->writeln(
                sprintf('<error>Action %s is not allowed.</error>',$action)
            );
            return;
        }

        $method = $action . 'Action';
        $vars = call_user_func_array(array($this, $method), array($input, $output));

        if($vars) {
            $method = $action . 'Report';
            call_user_func_array(array($this, $method), array($output, $vars));
        }
    }
    
    protected function getRepositoryNameFromUrl($url)
    {
        $data = $this->configFile->read();
        $repositories = array_key_exists('repositories', $data) ? $data['repositories'] : array();        
        foreach($repositories as $key=>$repository) {
            if(trim($repository['url'],'/') == trim($url,'/'))
            {
                return $key;
            }
        }
        
        return false;        
    }
    
    protected function removeAction(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('first');        
        
        $this->configSource->removeRepository($name);
        
        if(strpos($name, 'http') === 0) {
            $real_name = $this->getRepositoryNameFromUrl($name);    
            if(!$real_name)
            {
                continue;
            }
            $this->configSource->removeRepository($real_name);
        }
        
        $output->writeln(sprintf("Removed %s", $name));
    }
    
    protected function addAction(InputInterface $input, OutputInterface $output)
    {
        $name  = $input->getArgument('first');
        $url   = $input->getArgument('second');
        $type  = $input->getArgument('third');
        
        if(!$name) {
            $output->writeln('<error>First parameter (name) missing</error>');
        }
        
        if(!$url) {
            $output->writeln('<error>Second parameter (URL) missing</error>');
        }        
        
        //default to composer type, seems most common case
        $type = $type ? $type : 'composer';
        
        if(!in_array($type, $this->getAllowedRepositoryTypes())) {
            $output->writeln('<error>Invalid Repository Type</error>');
            return;
        }        
        
        $config = array(
            'type'  => $type,
            'url'   => $url
        );
        
        $this->configSource->addRepository($name, $config);
        
        $vars = new \stdClass;
        $vars->message = sprintf('Repository %s added', $url);
        return $vars;
    }
    
    protected function addReport($output, $vars)
    {
        $output->writeln(sprintf('<info>%s</info>', $vars->message));
    }
        
    protected function packagistAction(InputInterface $input, OutputInterface $output)
    {
        $switch = $input->getArgument('first');
        if(!$switch || ($switch != 'enable' && $switch != 'disable')) {
            $output->writeln(sprintf('<error>Invalid first argument, [%s], must be "enabled" or "disabled"</error>', $switch));
            return;
        }
        
        if($switch == 'enable') {            
            $this->configSource->removeRepository('packagist');            
            $message = 'Re-enabled Packagist Support';
        }
        else if($switch != 'disabled') {
            $packagist = new \stdClass;
            $packagist->packagist = false;
            
            $this->configSource->addRepository('packagist', $packagist);
            $message = 'Disabled Packagist Support';        
        }        

        $vars = new \stdClass;
        $vars->message = $message;
        return $vars;
    }
    
    protected function packagistReport(OutputInterface $output, \stdClass $vars)
    {
        $output->writeln('<info>' . $vars->message . '</info>');
    }

    protected function listAction(InputInterface $input, OutputInterface $output)
    {
        $data = $this->configFile->read();
        $repositories = array_key_exists('repositories', $data) ? $data['repositories'] : array();
        
        $packagist = false;
        $by_type    = array();
        foreach($repositories as $key=>$repository) {
            if($key == 'packagist') {
                $packagist = $repository;
                continue;
            }
            $type = array_key_exists('type', $repository) ? $repository['type'] : 'unknown';
            $by_type[$repository['type']][] = $repository;
        }
        
        $vars = new \stdClass;
        $vars->packagist = $packagist;
        $vars->by_type   = $by_type;
        return $vars;
    }
    
    protected function listReport(OutputInterface $output, \stdClass $vars)
    {
        $packagist = $vars->packagist;
        $output->writeln('');
        if($packagist) {
            $state = array_key_exists('packagist', $packagist) ? $packagist['packagist'] : false;
            $state = $state ? 'Enabled' : 'Disabled';
            $output->writeln(sprintf('<info>Built in packagist.org</info>: %s',$state));
        }
        else
        {
            $output->writeln("No Explicit Packagist Configuration");
        }
        
        $output->writeln('');
        
        $by_type = $vars->by_type;
        $found   = false;
        foreach($by_type as $type=>$repositories) {
            $found = true;
            $output->writeln('<info>Repository Type</info>: ' . $type);
            $output->writeln('--------------------------------------------------');
            foreach($repositories as $repository) {
                $url = array_key_exists('url', $repository) ? $repository['url'] : 'Repository Found, but no URL configured';
                $output->writeln('- ' . $url);
            }
            $output->writeln('');
        }
        
        if(!$found) {
            $output->writeln('No configured repositories.');
        }
    }
}