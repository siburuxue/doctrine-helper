<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

abstract class Driver
{
    public string $tableName = '';
    public array $tableInfo = [];
    public string $entityName = "";

    public string $schemaStr = "";

    public function __construct(
        public string $namespace,
        public string $type,
        public string $tableList,
        public string $ucfirst,
        public string $withoutTablePrefix,
        public string $database,
        public string $entityDir,
        public string $repositoryDir,
        public Connection $connection,
        public string $schema,
    ) {
        if (empty($namespace)) {
            $this->namespace = "App\\Entity";
        }
        if (empty($type)) {
            $this->type = "attribute";
        }
        if(!empty($this->schema)){
            $this->schemaStr = ", schema: '{$this->schema}'";
        }
    }

    abstract public function getTableList();

    abstract public function getTableInfo();

    abstract public function makeIndexes();

    abstract public function makeProperties();

    public static function create(
        string $namespace,
        string $type,
        string $tableList,
        string $ucfirst,
        string $withoutTablePrefix,
        string $database,
        string $entityDir,
        string $repositoryDir,
        Connection $connection,
        string $schema,
    ) {
        return new static(
            $namespace,
            $type,
            $tableList,
            $ucfirst,
            $withoutTablePrefix,
            $database,
            $entityDir,
            $repositoryDir,
            $connection,
            $schema,
        );
    }

    public function import(): void
    {
        $tableList = static::getTableList();
        if (!empty($this->tableList)) {
            $tables = trim($this->tableList, ',');
            $tables = explode(',', $tables);
            $diff = array_diff($tables, $tableList);
            if(!empty($diff)){
                throw new \Exception("Tables is not exist: " . implode(", ", $diff));
            }
            $tableList = $tables;
        }
        foreach ($tableList as $tableName) {
            $this->tableName = $tableName;
            $this->do($this->tableName);
        }
    }

    public function do($tableName): void
    {
        $this->tableInfo = $this->getTableInfo($tableName);
        $this->makeEntity($tableName);
        $this->makeRepository();
    }

    private function makeRepository(): void
    {
        $fileName = $this->entityName . "Repository.php";
        $filePath = $this->repositoryDir . $fileName;
        if (!file_exists($filePath)) {
            $content = <<<EOF
<?php

namespace App\Repository;

use {$this->namespace}\\{$this->entityName};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<{$this->entityName}>
 *
 * @method {$this->entityName}|null find(\$id, \$lockMode = null, \$lockVersion = null)
 * @method {$this->entityName}|null findOneBy(array \$criteria, array \$orderBy = null)
 * @method {$this->entityName}[]    findAll()
 * @method {$this->entityName}[]    findBy(array \$criteria, array \$orderBy = null, \$limit = null, \$offset = null)
 */
class {$this->entityName}Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry \$registry)
    {
        parent::__construct(\$registry, {$this->entityName}::class);
    }

//    /**
//     * @return {$this->entityName}[] Returns an array of {$this->entityName} objects
//     */
//    public function findByExampleField(\$value): array
//    {
//        return \$this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', \$value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField(\$value): ?{$this->entityName}
//    {
//        return \$this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', \$value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

EOF;
            file_put_contents($filePath, $content);
        } else {
            $content = file_get_contents($filePath);
            if (!str_contains($content, "@extends")) {
                $replace = <<<EOF
/**
 * @extends ServiceEntityRepository<{$this->entityName}>
 *
 * @method {$this->entityName}|null find(\$id, \$lockMode = null, \$lockVersion = null)
 * @method {$this->entityName}|null findOneBy(array \$criteria, array \$orderBy = null)
 * @method {$this->entityName}[]    findAll()
 * @method {$this->entityName}[]    findBy(array \$criteria, array \$orderBy = null, \$limit = null, \$offset = null)
 */
class {$this->entityName}Repository extends ServiceEntityRepository
EOF;
                $origin = "class {$this->entityName}Repository extends ServiceEntityRepository";
                $newContent = str_replace($origin, $replace, $content);
                file_put_contents($filePath, $newContent);
            }
        }
    }

    private function makeEntity(string $tableName): void
    {
        if (!empty($this->withoutTablePrefix) && str_starts_with($this->tableName, $this->withoutTablePrefix)) {
            $tableName = substr($tableName, strlen($this->withoutTablePrefix));
        }
        $entityName = $this->upperName($tableName);
        $this->entityName = $entityName;
        $fileName = $this->entityName . ".php";
        $filePath = $this->entityDir . $fileName;
        $indexes = static::makeIndexes();
        [$properties, $getSet] = static::makeProperties();
        $content = <<<EOF
<?php

namespace {$this->namespace};

use App\Repository\\{$entityName}Repository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: '{$this->tableName}'{$this->schemaStr})]{$indexes}
#[ORM\Entity(repositoryClass: {$entityName}Repository::class)]
class {$entityName}
{
{$properties}

{$getSet}
}

EOF;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        file_put_contents($filePath, $content);
    }

    public function upperName(string $name): string
    {
        return str_replace("_", "", ucwords(strtolower($name), '_'));
    }

    public function upper(string $name): string
    {
        return lcfirst($this->upperName($name));
    }
}