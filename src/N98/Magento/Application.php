<?php

namespace N98\Magento;

use Magento\Framework\ObjectManager\ObjectManager;
use N98\Magento\Application\Console\Events;
use N98\Magento\Command\ConfigurationLoader;
use N98\Util\ArrayFunctions;
use N98\Util\Console\Helper\TwigHelper;
use N98\Util\Console\Helper\MagentoHelper;
use N98\Util\OperatingSystem;
use N98\Util\BinaryString;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends BaseApplication
{
    /**
     * @var string
     */
    const APP_NAME = 'n98-magerun2';

    /**
     * @var string
     */
    const APP_VERSION = '1.1.0';

    /**
     * @type int
     */
    const MAGENTO_MAJOR_VERSION_1 = 1;

    /**
     * @type int
     */
    const MAGENTO_MAJOR_VERSION_2 = 2;

    /**
     * @var string
     */
    private static $logo = "
          ____  ____                                                   ___ 
   ____  / __ \\( __ )      ____ ___  ____ _____ ____  _______  ______ |__ \\
  / __ \\/ /_/ / __  |_____/ __ `__ \\/ __ `/ __ `/ _ \\/ ___/ / / / __ \\__/ /
 / / / /\\__, / /_/ /_____/ / / / / / /_/ / /_/ /  __/ /  / /_/ / / / / __/
/_/ /_//____/\\____/     /_/ /_/ /_/\\__,_/\\__, /\\___/_/   \\__,_/_/ /_/____/
                                        /____/                             
";
    /**
     * @var \Composer\Autoload\ClassLoader
     */
    protected $autoloader;

    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var ConfigurationLoader
     */
    protected $configurationLoader = null;

    /**
     * @var array
     */
    protected $partialConfig = array();

    /**
     * @var string
     */
    protected $_magentoRootFolder = null;

    /**
     * @var bool
     */
    protected $_magentoEnterprise = false;

    /**
     * @var int
     */
    protected $_magentoMajorVersion = self::MAGENTO_MAJOR_VERSION_2;

    /**
     * @var EntryPoint
     */
    protected $_magento2EntryPoint = null;

    /**
     * @var bool
     */
    protected $_isPharMode = false;

    /**
     * @var bool
     */
    protected $_magerunStopFileFound = false;

    /**
     * @var string
     */
    protected $_magerunStopFileFolder = null;

    /**
     * @var bool
     */
    protected $_isInitialized = false;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    protected $dispatcher;

    /**
     * If root dir is set by root-dir option this flag is true
     *
     * @var bool
     */
    protected $_directRootDir = false;

    /**
     * @var bool
     */
    protected $_magentoDetected = false;

    /**
     * @var ObjectManager
     */
    protected $_objectManager = null;

    /**
     * @param \Composer\Autoload\ClassLoader $autoloader
     */
    public function __construct($autoloader = null)
    {
        $this->autoloader = $autoloader;
        parent::__construct(self::APP_NAME, self::APP_VERSION);
    }

    /**
     * @return \Symfony\Component\Console\Input\InputDefinition|void
     */
    protected function getDefaultInputDefinition()
    {
        $inputDefinition = parent::getDefaultInputDefinition();
        $rootDirOption = new InputOption(
            '--root-dir',
            '',
            InputOption::VALUE_OPTIONAL,
            'Force magento root dir. No auto detection'
        );
        $inputDefinition->addOption($rootDirOption);

        $skipExternalConfig = new InputOption(
            '--skip-config',
            '',
            InputOption::VALUE_OPTIONAL,
            'Do not load any custom config.'
        );
        $inputDefinition->addOption($skipExternalConfig);

        $skipExternalConfig = new InputOption(
            '--skip-root-check',
            '',
            InputOption::VALUE_OPTIONAL,
            'Do not check if n98-magerun runs as root'
        );
        $inputDefinition->addOption($skipExternalConfig);

        $skipMagento2CoreCommands = new InputOption(
            '--skip-core-commands',
            '',
            InputOption::VALUE_OPTIONAL,
            'Do not include Magento 2 core commands'
        );
        $inputDefinition->addOption($skipMagento2CoreCommands);

        return $inputDefinition;
    }

    /**
     * Get names of sub-folders to be scanned during Magento detection
     * @return array
     */
    public function getDetectSubFolders()
    {
        if (isset($this->partialConfig['detect'])) {
            if (isset($this->partialConfig['detect']['subFolders'])) {
                return $this->partialConfig['detect']['subFolders'];
            }
        }
        return array();
    }

    /**
     * Search for magento root folder
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    public function detectMagento(InputInterface $input = null, OutputInterface $output = null)
    {
        // do not detect magento twice
        if ($this->_magentoDetected) {
            return;
        }

        if ($this->getMagentoRootFolder() === null) {
            $this->_checkRootDirOption();
            if (function_exists('exec')) {
                if (OperatingSystem::isWindows()) {
                    $folder = exec('@echo %cd%'); // @TODO not currently tested!!!
                } else {
                    $folder = exec('pwd');
                }
            } else {
                $folder = getcwd();
            }
        } else {
            $folder = $this->getMagentoRootFolder();
        }

        $this->getHelperSet()->set(new MagentoHelper($input, $output), 'magento');
        $magentoHelper = $this->getHelperSet()->get('magento'); /* @var $magentoHelper MagentoHelper */
        if (!$this->_directRootDir) {
            $subFolders = $this->getDetectSubFolders();
        } else {
            $subFolders = array();
        }

        $this->_magentoDetected = $magentoHelper->detect($folder, $subFolders);
        $this->_magentoRootFolder = $magentoHelper->getRootFolder();
        $this->_magentoEnterprise = $magentoHelper->isEnterpriseEdition();
        $this->_magentoMajorVersion = $magentoHelper->getMajorVersion();
        $this->_magerunStopFileFound = $magentoHelper->isMagerunStopFileFound();
        $this->_magerunStopFileFolder = $magentoHelper->getMagerunStopFileFolder();
    }

    /**
     * Add own helpers to helperset.
     *
     * @return void
     */
    protected function registerHelpers()
    {
        $helperSet = $this->getHelperSet();

        // Twig
        $twigBaseDirs = array(
            __DIR__ . '/../../../res/twig'
        );
        if (isset($this->config['twig']['baseDirs']) && is_array($this->config['twig']['baseDirs'])) {
            $twigBaseDirs = array_merge(array_reverse($this->config['twig']['baseDirs']), $twigBaseDirs);
        }
        $helperSet->set(new TwigHelper($twigBaseDirs), 'twig');

        foreach ($this->config['helpers'] as $helperName => $helperClass) {
            if (class_exists($helperClass)) {
                $helperSet->set(new $helperClass(), $helperName);
            }
        }
    }

    /**
     * Adds autoloader prefixes from user's config
     *
     * @param OutputInterface $output
     */
    protected function registerCustomAutoloaders(OutputInterface $output)
    {
        if (isset($this->config['autoloaders']) && is_array($this->config['autoloaders'])) {
            foreach ($this->config['autoloaders'] as $prefix => $path) {
                $this->autoloader->add($prefix, $path);

                if (OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity()) {
                    $output->writeln(
                        '<debug>Registrered PSR-2 autoloader </debug> <info>'
                        . $prefix . '</info> -> <comment>' . $path . '</comment>'
                    );
                }
            }
        }

        if (isset($this->config['autoloaders_psr4']) && is_array($this->config['autoloaders_psr4'])) {
            foreach ($this->config['autoloaders_psr4'] as $prefix => $path) {
                $this->autoloader->addPsr4($prefix, $path);

                if (OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity()) {
                    $output->writeln(
                        '<debug>Registrered PSR-4 autoloader </debug> <info>'
                        . $prefix . ' </info> -> <comment>' . $path . '</comment>'
                    );
                }
            }
        }
    }

    /**
     * Try to bootstrap magento 2 and load cli application
     *
     * @param OutputInterface $output
     */
    protected function registerMagentoCoreCommands(OutputInterface $output)
    {
        if ($this->getMagentoRootFolder()) {
            // Magento was found -> register core cli commands
            require_once $this->getMagentoRootFolder() . '/app/bootstrap.php';

            $coreCliApplication = new \Magento\Framework\Console\Cli();
            $coreCliApplicationCommands = $coreCliApplication->all();

            foreach ($coreCliApplicationCommands as $coreCliApplicationCommand) {
                $this->add($coreCliApplicationCommand);

                if (OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity()) {
                    $output->writeln(
                        '<debug>Added core command </debug><comment>'
                        . get_class($coreCliApplicationCommand) . '</comment>'
                    );
                }
            }
        }
    }

    /**
     * @param OutputInterface $output
     */
    protected function registerCustomCommands(OutputInterface $output)
    {
        if (isset($this->config['commands']['customCommands'])
            && is_array($this->config['commands']['customCommands'])
        ) {
            foreach ($this->config['commands']['customCommands'] as $commandClass) {
                if (is_array($commandClass)) { // Support for key => value (name -> class)
                    $resolvedCommandClass = current($commandClass);
                    $command = new $resolvedCommandClass();
                    $command->setName(key($commandClass));
                } else {
                    $command = new $commandClass();
                }
                $this->add($command);

                if (OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity()) {
                    $output->writeln(
                        '<debug>Added command </debug><comment>' . get_class($command) . '</comment>'
                    );
                }
            }
        }
    }



    /**
     * Override standard command registration. We want alias support.
     *
     * @param \Symfony\Component\Console\Command\Command $command
     * @return \Symfony\Component\Console\Command\Command
     */
    public function add(Command $command)
    {
        $this->registerConfigCommandAlias($command);

        return parent::add($command);
    }

    /**
     * @param \Symfony\Component\Console\Command\Command $command
     */
    protected function registerConfigCommandAlias(Command $command)
    {
        if ($this->hasConfigCommandAliases()) {
            foreach ($this->config['commands']['aliases'] as $alias) {
                if (!is_array($alias)) {
                    continue;
                }

                $aliasCommandName = key($alias);
                $commandString = $alias[$aliasCommandName];

                list($originalCommand) = explode(' ', $commandString);
                if ($command->getName() == $originalCommand) {
                    $currentCommandAliases = $command->getAliases();
                    $currentCommandAliases[] = $aliasCommandName;
                    $command->setAliases($currentCommandAliases);
                }
            }
        }
    }

    /**
     * @return bool
     */
    private function hasConfigCommandAliases()
    {
        return isset($this->config['commands']['aliases']) && is_array($this->config['commands']['aliases']);
    }

    /**
     * @param bool $mode
     */
    public function setPharMode($mode)
    {
        $this->_isPharMode = $mode;
    }

    /**
     * @return bool
     */
    public function isPharMode()
    {
        return $this->_isPharMode;
    }

    /**
     * @TODO Move logic into "EventSubscriber"
     *
     * @param OutputInterface $output
     * @return bool
     */
    public function checkVarDir(OutputInterface $output)
    {
        if (OutputInterface::VERBOSITY_NORMAL <= $output->getVerbosity()) {
            $tempVarDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'magento' . DIRECTORY_SEPARATOR .  'var';

            if (is_dir($tempVarDir)) {
                $this->detectMagento(null, $output);
                /* If magento is not installed yet, don't check */
                if ($this->_magentoRootFolder === null
                    || !file_exists($this->_magentoRootFolder . '/app/etc/local.xml')
                ) {
                    return;
                }

                try {
                    $this->initMagento();
                } catch (\Exception $e) {
                    $message = 'Cannot initialize Magento. Please check your configuration. '
                             . 'Some n98-magerun command will not work. Got message: ';
                    if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $output->getVerbosity()) {
                        $message .= $e->getTraceAsString();
                    } else {
                        $message .= $e->getMessage();
                    }
                    $output->writeln($message);

                    return;
                }

                $configOptions = new \Mage_Core_Model_Config_Options();
                $currentVarDir = $configOptions->getVarDir();

                if ($currentVarDir == $tempVarDir) {
                    $output->writeln(sprintf('<warning>Fallback folder %s is used in n98-magerun</warning>', $tempVarDir));
                    $output->writeln('');
                    $output->writeln('n98-magerun is using the fallback folder. If there is another folder configured for Magento, this can cause serious problems.');
                    $output->writeln('Please refer to https://github.com/netz98/n98-magerun/wiki/File-system-permissions for more information.');
                    $output->writeln('');
                } else {
                    $output->writeln(sprintf('<warning>Folder %s found, but not used in n98-magerun</warning>', $tempVarDir));
                    $output->writeln('');
                    $output->writeln(sprintf('This might cause serious problems. n98-magerun is using the configured var-folder <comment>%s</comment>', $currentVarDir));
                    $output->writeln('Please refer to https://github.com/netz98/n98-magerun/wiki/File-system-permissions for more information.');
                    $output->writeln('');

                    return false;
                }
            }
        }
    }

    public function initMagento()
    {
        if ($this->getMagentoRootFolder() !== null) {
            if ($this->_magentoMajorVersion == self::MAGENTO_MAJOR_VERSION_2) {
                $this->_initMagento2();
            } else {
                $this->_initMagento1();
            }

            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    public function getHelp()
    {
        return self::$logo . parent::getHelp();
    }

    public function getLongVersion()
    {
        return parent::getLongVersion() . ' by <info>netz98 new media GmbH</info>';
    }

    /**
     * @return boolean
     */
    public function isMagentoEnterprise()
    {
        return $this->_magentoEnterprise;
    }

    /**
     * @return string
     */
    public function getMagentoRootFolder()
    {
        return $this->_magentoRootFolder;
    }

    /**
     * @param string $magentoRootFolder
     */
    public function setMagentoRootFolder($magentoRootFolder)
    {
        $this->_magentoRootFolder = $magentoRootFolder;
    }

    /**
     * @return int
     */
    public function getMagentoMajorVersion()
    {
        return $this->_magentoMajorVersion;
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public function getAutoloader()
    {
        return $this->autoloader;
    }

    /**
     * @param \Composer\Autoload\ClassLoader $autoloader
     */
    public function setAutoloader($autoloader)
    {
        $this->autoloader = $autoloader;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param array $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return boolean
     */
    public function isMagerunStopFileFound()
    {
        return $this->_magerunStopFileFound;
    }

    /**
     * Runs the current application with possible command aliases
     *
     * @param InputInterface $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $event = new Application\Console\Event($this, $input, $output);
        $this->dispatcher->dispatch(Events::RUN_BEFORE, $event);

        /**
         * only for compatibility to old versions.
         */
        $event = new ConsoleEvent(new Command('dummy'), $input, $output);
        $this->dispatcher->dispatch('console.run.before', $event);

        $input = $this->checkConfigCommandAlias($input);
        if ($output instanceof ConsoleOutput) {
            $this->checkVarDir($output->getErrorOutput());
        }

        if (OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity()) {
            $output->writeln('DEBUG');
        }

        return parent::doRun($input, $output);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @return \Symfony\Component\Console\Input\ArgvInput|\Symfony\Component\Console\Input\InputInterface
     */
    protected function checkConfigCommandAlias(InputInterface $input)
    {
        if ($this->hasConfigCommandAliases()) {
            foreach ($this->config['commands']['aliases'] as $alias) {
                if (is_array($alias)) {
                    $aliasCommandName = key($alias);
                    if ($input->getFirstArgument() == $aliasCommandName) {
                        $aliasCommandParams = array_slice(BinaryString::trimExplodeEmpty(' ', $alias[$aliasCommandName]), 1);
                        if (count($aliasCommandParams) > 0) {
                            // replace with aliased data
                            $mergedParams = array_merge(
                                array_slice($_SERVER['argv'], 0, 2),
                                $aliasCommandParams,
                                array_slice($_SERVER['argv'], 2)
                            );
                            $input = new ArgvInput($mergedParams);
                        }
                    }
                }
            }
            return $input;
        }
        return $input;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $input) {
            $input = new ArgvInput();
        }

        if (null === $output) {
            $output = new ConsoleOutput();
        }
        $this->_addOutputStyles($output);
        if ($output instanceof ConsoleOutput) {
            $this->_addOutputStyles($output->getErrorOutput());
        }

        $this->configureIO($input, $output);

        try {
            $this->init(array(), $input, $output);
        } catch (\Exception $e) {
            $output = new ConsoleOutput();
            $this->renderException($e, $output);
        }

        $return = parent::run($input, $output);

        // Fix for no return values -> used in interactive shell to prevent error output
        if ($return === null) {
            return 0;
        }

        return $return;
    }

    /**
     * @param array $initConfig
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function init($initConfig = array(), InputInterface $input = null, OutputInterface $output = null)
    {
        if (!$this->_isInitialized) {
            // Suppress DateTime warnings
            date_default_timezone_set(@date_default_timezone_get());

            $loadExternalConfig = !$this->_checkSkipConfigOption();

            if ($output === null) {
                $output = new NullOutput();
            }

            $configLoader = $this->getConfigurationLoader($initConfig, $output);
            $this->partialConfig = $configLoader->getPartialConfig($loadExternalConfig);
            $this->detectMagento($input, $output);
            $configLoader->loadStageTwo($this->_magentoRootFolder, $loadExternalConfig, $this->_magerunStopFileFolder);
            $this->config = $configLoader->toArray();;
            $this->dispatcher = new EventDispatcher();
            $this->setDispatcher($this->dispatcher);

            if ($this->autoloader) {

                /**
                 * Include commands shipped by Magento 2 core
                 */
                if (!$this->_checkSkipMagento2CoreCommandsOption()) {
                    $this->registerMagentoCoreCommands($output);
                }

                $this->registerCustomAutoloaders($output);
                $this->registerEventSubscribers();
                $this->registerCustomCommands($output);
            }

            $this->registerHelpers();

            $this->_isInitialized = true;
        }
    }

    /**
     * @param array $initConfig
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function reinit($initConfig = array(), InputInterface $input = null, OutputInterface $output = null)
    {
        $this->_isInitialized = false;
        $this->init($initConfig, $input, $output);
    }

    /**
     * @return void
     */
    protected function registerEventSubscribers()
    {
        foreach ($this->config['event']['subscriber'] as $subscriberClass) {
            $subscriber = new $subscriberClass();
            $this->dispatcher->addSubscriber($subscriber);
        }
    }

    /**
     * @return bool
     */
    protected function _checkSkipConfigOption()
    {
        $skipConfigOption = getopt('', array('skip-config'));

        return count($skipConfigOption) > 0;
    }

    /**
     * @return bool
     */
    protected function _checkSkipMagento2CoreCommandsOption()
    {
        $skipConfigOption = getopt('', array('skip-core-commands'));

        return count($skipConfigOption) > 0;
    }

    /**
     * @return string
     */
    protected function _checkRootDirOption()
    {
        $specialGlobalOptions = getopt('', array('root-dir:'));

        if (count($specialGlobalOptions) > 0) {
            if (isset($specialGlobalOptions['root-dir'][0])
                && $specialGlobalOptions['root-dir'][0] == '~'
            ) {
                $specialGlobalOptions['root-dir'] = OperatingSystem::getHomeDir() . substr($specialGlobalOptions['root-dir'], 1);
            }
            $folder = realpath($specialGlobalOptions['root-dir']);
            $this->_directRootDir = true;
            if (is_dir($folder)) {
                \chdir($folder);

                return;
            }
        }
    }

    protected function _initMagento1()
    {
        $magento1Hint = <<<'MAGENTO1HINT'
You are running a Magento 1.x instance. This version of n98-magerun is not compatible
with Magento 1.x. Please use n98-magerun (version 1) for this shop.

A current version of the software can be downloaded on github.

<info>Download with curl
------------------</info>

    <comment>curl -o n98-magerun.phar https://raw.githubusercontent.com/netz98/n98-magerun/master/n98-magerun.phar</comment>

<info>Download with wget
------------------</info>

    <comment>curl -o n98-magerun.phar https://raw.githubusercontent.com/netz98/n98-magerun/master/n98-magerun.phar</comment>

MAGENTO1HINT;

        $output = new ConsoleOutput();

        $output->writeln(array(
            '',
            $this->getHelperSet()->get('formatter')->formatBlock('Compatibility Notice', 'bg=blue;fg=white', true),
            ''
        ));

        $output->writeln($magento1Hint);
        exit;
    }

    /**
     * @return void
     */
    protected function _initMagento2()
    {
        require_once $this->getMagentoRootFolder() . '/app/bootstrap.php';

        $params = $_SERVER;
        $params[\Magento\Store\Model\StoreManager::PARAM_RUN_CODE] = 'admin';
        $params[\Magento\Store\Model\Store::CUSTOM_ENTRY_POINT_PARAM] = true;
        $params['entryPoint'] = basename(__FILE__);

        $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $params);
        /** @var \Magento\Framework\App\Cron $app */
        $app = $bootstrap->createApplication('N98\Magento\Framework\App\Magerun', []);
        /* @var $app \N98\Magento\Framework\App\Magerun */
        $app->launch();

        $this->_objectManager = $app->getObjectManager();
    }

    /**
     * @return \Symfony\Component\EventDispatcher\EventDispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * @param array $initConfig
     * @param OutputInterface $output
     * @return ConfigurationLoader
     */
    public function getConfigurationLoader($initConfig = array(), OutputInterface $output)
    {
        if ($this->configurationLoader === null) {
            $this->configurationLoader = new ConfigurationLoader(
                ArrayFunctions::mergeArrays($this->config, $initConfig),
                $this->isPharMode(),
                $output
            );
        }

        return $this->configurationLoader;
    }

    /**
     * @param \N98\Magento\Command\ConfigurationLoader $configurationLoader
     *
     * @return $this
     */
    public function setConfigurationLoader($configurationLoader)
    {
        $this->configurationLoader = $configurationLoader;

        return $this;
    }

    /**
     * @param OutputInterface $output
     */
    protected function _addOutputStyles(OutputInterface $output)
    {
        $output->getFormatter()->setStyle('debug', new OutputFormatterStyle('magenta', 'white'));
        $output->getFormatter()->setStyle('warning', new OutputFormatterStyle('red', 'yellow', array('bold')));
    }

    /**
     * @return ObjectManager
     */
    public function getObjectManager()
    {
        return $this->_objectManager;
    }
}
