<?php

namespace App\Infrastructure\Console\Command\Magento;

use App\Domain\Fixture;
use App\Domain\Magento\SqlExportTwigExtension;
use App\Infrastructure\Configuration\YamlFileLoader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\GlobFileLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class AttributeOptionCommand extends Command
{
    protected static $defaultName = 'magento:attribute-options';

    private $logger;

    public function __construct(string $name = null, LoggerInterface $logger = null)
    {
        parent::__construct($name);
        $this->logger = $logger ?? new NullLogger();
    }

    protected function configure()
    {
        $this->setDescription('Generate the attribute options fixtures files depending on your catalog.yaml configuration and your Magento data.');

        $this->addOption(
            'dsn',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Specify a Data Source Name for PDO, defaults to APP_DSN environment variable.'
        );

        $this->addOption(
            'username',
            'u',
            InputOption::VALUE_OPTIONAL,
            'Specify the username for PDO, defaults to APP_USERNAME environment variable.'
        );

        $this->addOption(
            'password',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Specify the password for PDO, defaults to APP_PASSWORD environment variable.'
        );

        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Specify the path to the catalog config file.'
        );

        $this->addArgument(
            'output',
            InputArgument::OPTIONAL,
            'Specify the output path, defaults to CWD.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $locator = new FileLocator([
            getcwd(),
        ]);

        $loader = new DelegatingLoader(new LoaderResolver([
            new GlobFileLoader($locator),
            new YamlFileLoader($locator)
        ]));

        $style = new SymfonyStyle($input, $output);

        try {
            if (!empty($file = $input->getOption('config'))) {
                $config = $loader->load($file, 'glob');
            } else {
                $config = $loader->load('{.,}catalog.y{a,}ml', 'glob');
            }
        } catch (LoaderLoadException $e) {
            $style->error($e->getMessage());
            return -1;
        }

        try {
            $pdo = new \PDO(
                $input->getOption('dsn') ?? $_ENV['APP_DSN'] ?? 'mysql:host=localhost;dbname=magento',
                $input->getOption('username') ?? $_ENV['APP_USERNAME'] ?? 'root',
                $input->getOption('password') ?? $_ENV['APP_PASSWORD'] ?? null,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\PDOException $e) {
            $style->error($e->getMessage());
            $style->error(strtr('Connection parameters were: %dsn% with user %user%, using password: %password%.', [
                '%dsn%' => $input->getOption('dsn') ?? $_ENV['APP_DSN'] ?? 'mysql:host=localhost;dbname=magento',
                '%user%' => $input->getOption('username') ?? $_ENV['APP_USERNAME'] ?? 'root',
                '%password%' => ($input->getOption('password') ?? $_ENV['APP_PASSWORD'] ?? null) !== null ? 'Yes' : 'No',
            ]));
            return -1;
        }

        $twig = new Environment(
            new FilesystemLoader([
                __DIR__ . '/../../../Resources/templates'
            ]),
            [
                'autoescape' => false,
                'debug' => true,
            ]
        );

        $twig->addExtension(new DebugExtension());
        $twig->addExtension(new SqlExportTwigExtension());

        (new Fixture\Command\ExtractAttributeOptions($pdo, $twig, $this->logger))(
            new \SplFileObject(($input->getArgument('output') ?? getcwd()) . '/attribute_options.csv', 'x'),
            array_keys($config['attributes']),
            array_keys($config['locales']),
            $config['codes_mapping']
        );

        $style->writeln('attribute options <fg=green>ok</>', SymfonyStyle::OUTPUT_PLAIN);

        return 0;
    }
}