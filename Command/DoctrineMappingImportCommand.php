<?php

namespace Doctrine\Helper\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'doctrine-helper:mapping:import',
    description: 'instead of `php bin/console doctrine:mapping:import App\\Entity attribute --path=src/Entity` [--ucfirst=true] [--table=test,test1] [--without-table-prefix=eq_]',
)]
class DoctrineMappingImportCommand extends Command
{
    private array $driverMap = [
        'pdo_mysql' => "MySQL",
        'mysqli' => "MySQL",
        'pdo_pgsql' => "PostgreSQL",
        'pgsql' => "PostgreSQL",
        'sqlsrv' => "SQLServer",
        'pdo_sqlsrv' => "SQLServer",
        'oci8' => "Oracle",
        'pdo_sqlite' => "Sqlite",
        'sqlite3' => "Sqlite",
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('namespace', InputArgument::OPTIONAL, "the entity's namespace, App\\Entity on default")
            ->addArgument('type', InputArgument::OPTIONAL, "attribute, xml, yaml, php; attribute on default")
            ->addOption('path', "", InputOption::VALUE_OPTIONAL, "the Entity's path, src/Entity on default")
            ->addOption('table', "t", InputOption::VALUE_OPTIONAL, 'the import tables of the database')
            ->addOption('ucfirst', "", InputOption::VALUE_OPTIONAL, 'convert first character of word to uppercase')
            ->addOption('schema', "", InputOption::VALUE_OPTIONAL, 'when connecting to a postgresql database, synchronize the tables of the specified schema')
            ->addOption('without-table-prefix', "", InputOption::VALUE_OPTIONAL, 'without table prefix');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $database = $this->connection->getDatabase();
        $param = $this->connection->getParams();
        if(!isset($this->driverMap[$param['driver']])){
            $io->error("Unsupported Driver: {$param['driver']}");
            return Command::FAILURE;
        }
        $driver = $this->driverMap[$param['driver']];
        $root = $this->kernel->getProjectDir();
        $entityDir = $root . "/src/Entity/";
        $repositoryDir = $root . "/src/Repository/";
        if (!file_exists($entityDir)) {
            mkdir($entityDir);
        }
        if (!file_exists($repositoryDir)) {
            mkdir($repositoryDir);
        }
        $namespace = (string)$input->getArgument('namespace');
        $type = (string)$input->getArgument('type');
        if(!empty($type) && !in_array($type, ["attribute", "xml", "yaml", "php"])){
            $io->error("The specified export driver '{$type}' does not exist");
            $io->warning("The option path only support yaml, xml, php, attribute");
            return Command::FAILURE;
        }
        $path = (string)$input->getOption('path');
        if (!empty($path)) {
            $entityDir = $root . "/" . $path . "/";
            if (!file_exists($entityDir)) {
                mkdir($entityDir, 0755, true);
            }
        }
        $tableList = (string)$input->getOption('table');
        $ucfirst = (string)$input->getOption('ucfirst');
        $schema = (string)$input->getOption('schema');
        // 手动指定schema, postgresql数据库专用
        if(!empty($schema)){
            if($param['driver'] == "pdo_pgsql" || $param['driver'] == "pgsql"){
                $this->connection->executeStatement('SET search_path TO ' . $schema);
            }else{
                // 目前只有postgresql中这个参数才有意义，其他数据库不需要这个参数，所以设置成空，以凸显schema不为空时必定链接的是postgresql
                $schema = "";
            }
        }
        $withoutTablePrefix = (string)$input->getOption('without-table-prefix');
        $action = "\Doctrine\Helper\Driver\\$driver::create";
        try {
            $action(
                $namespace,
                $type,
                $tableList,
                $ucfirst,
                $withoutTablePrefix,
                $database,
                $entityDir,
                $repositoryDir,
                $this->connection,
                $schema,
            )->import();
            $io->success('Import success!');
            return Command::SUCCESS;
        }catch (\Exception $e){
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
