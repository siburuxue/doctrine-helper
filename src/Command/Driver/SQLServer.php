<?php

namespace App\Command\Driver;

use Doctrine\DBAL\Connection;

class SQLServer extends Driver
{
    public function __construct(
        string $namespace,
        string $type,
        string $tableList,
        string $ucfirst,
        string $withoutTablePrefix,
        string $database,
        string $entityDir,
        string $repositoryDir,
        Connection $connection,
    ) {
        parent::__construct(
            $namespace,
            $type,
            $tableList,
            $ucfirst,
            $withoutTablePrefix,
            $database,
            $entityDir,
            $repositoryDir,
            $connection,
        );
    }

    public function getTableList(): array
    {
        $rs = $this->connection->fetchAllAssociative("SELECT name FROM sys.sysobjects Where xtype='U' ORDER BY name");
        return array_column($rs, 'name');
    }

    public function getTableInfo(string $tableName = ""): array
    {
        $sql = "SELECT * FROM [INFORMATION_SCHEMA].[COLUMNS] WHERE TABLE_NAME = '{$tableName}'  order by ORDINAL_POSITION";
        return $this->connection->fetchAllAssociative($sql);
    }

    public function getPrimaryKeys(): array
    {
        $sql = <<<EOF
SELECT
    TableId=O.[object_id],
    TableName=O.Name,
    IndexId=ISNULL(KC.[object_id],IDX.index_id),
    IndexName=IDX.Name,
    IndexType=ISNULL(KC.type_desc,'Index'),
    Index_Column_id=IDXC.index_column_id,
    ColumnID=C.Column_id,
    ColumnName=C.Name,
    IsIdentity=COLUMNPROPERTY(C.object_id,C.Name,'IsIdentity'),
    PrimaryKey=CASE WHEN IDX.is_primary_key=1 THEN N'1'ELSE N'' END,
    [UQIQUE]=CASE WHEN IDX.is_unique=1 THEN N'1'ELSE N'' END
FROM sys.indexes IDX
 INNER JOIN sys.index_columns IDXC
            ON IDX.[object_id]=IDXC.[object_id]
                AND IDX.index_id=IDXC.index_id
 LEFT JOIN sys.key_constraints KC
           ON IDX.[object_id]=KC.[parent_object_id]
               AND IDX.index_id=KC.unique_index_id
 INNER JOIN sys.objects O
            ON O.[object_id]=IDX.[object_id]
 INNER JOIN sys.columns C
            ON O.[object_id]=C.[object_id]
                AND O.type='U'
                AND O.is_ms_shipped=0
                AND IDXC.Column_id=C.Column_id where O.name='{$this->tableName}' and IDX.is_primary_key = 1;
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, null, 'ColumnName');
    }

    public function getColumnComment()
    {
        $sql = <<<EOF

SELECT  B.name AS COLUMN_NAME, C.value AS COLUMN_COMMENT
FROM sys.tables A
INNER JOIN sys.columns B ON B.object_id = A.object_id
LEFT JOIN sys.extended_properties C ON C.major_id = B.object_id AND C.minor_id = B.column_id
WHERE A.name = '{$this->tableName}';
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, 'COLUMN_COMMENT', 'COLUMN_NAME');
    }

    public function makeIndexes(): string
    {
$sql = <<<EOF
SELECT
    TableId=O.[object_id],
    TableName=O.Name,
    IndexId=ISNULL(KC.[object_id],IDX.index_id),
    IndexName=IDX.Name,
    IndexType=ISNULL(KC.type_desc,'Index'),
    Index_Column_id=IDXC.index_column_id,
    ColumnID=C.Column_id,
    ColumnName=C.Name,
    IsIdentity=COLUMNPROPERTY(C.object_id,C.Name,'IsIdentity'),
    PrimaryKey=CASE WHEN IDX.is_primary_key=1 THEN N'1'ELSE N'' END,
    [UQIQUE]=CASE WHEN IDX.is_unique=1 THEN N'1'ELSE N'' END
FROM sys.indexes IDX
 INNER JOIN sys.index_columns IDXC
            ON IDX.[object_id]=IDXC.[object_id]
                AND IDX.index_id=IDXC.index_id
 LEFT JOIN sys.key_constraints KC
           ON IDX.[object_id]=KC.[parent_object_id]
               AND IDX.index_id=KC.unique_index_id
 INNER JOIN sys.objects O
            ON O.[object_id]=IDX.[object_id]
 INNER JOIN sys.columns C
            ON O.[object_id]=C.[object_id]
                AND O.type='U'
                AND O.is_ms_shipped=0
                AND IDXC.Column_id=C.Column_id where O.name='{$this->tableName}' and IDX.is_primary_key <> 1;
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        if (empty($rs)) {
            return "";
        }
        $indexes = [];
        $indexArray = [];
        foreach ($rs as $r) {
            if (isset($indexArray[$r['ColumnName']])) {
                $indexArray[$r['IndexName']]['Column_name'][] = $r['ColumnName'];
            } else {
                $indexArray[$r['IndexName']] = [
                    'Non_unique' => $r['UQIQUE'],
                    'Column_name' => [$r['ColumnName']]
                ];
            }
        }
        foreach ($indexArray as $key => $item) {
            $class = "ORM\Index";
            if ($item['Non_unique'] == '1') {
                $class = "ORM\UniqueConstraint";
            }
            $columns = implode(', ', array_map(function ($v) {
                return "'{$v}'";
            }, $item['Column_name']));
            $tmp = "#[{$class}(name: '{$key}', columns: [{$columns}])]";
            $indexes[] = $tmp;
        }
        return PHP_EOL . implode(PHP_EOL, $indexes);
    }

    public function makeProperties(): array
    {
        $properties = "";
        $getSet = "";
        $primaryKeyMap = $this->getPrimaryKeys();
        $columnCommentMap = $this->getColumnComment();
        $primaryArray = array_filter($this->tableInfo,function($v) use ($primaryKeyMap){
            return isset($primaryKeyMap[$v['COLUMN_NAME']]);
        });
        $othersArray = array_filter($this->tableInfo,function($v) use ($primaryKeyMap){
            return !isset($primaryKeyMap[$v['COLUMN_NAME']]);
        });
        $this->tableInfo = [];
        array_push($this->tableInfo, ...$primaryArray, ...$othersArray);
        foreach ($this->tableInfo as $item) {
            $type = $item['DATA_TYPE'];
            $columnName = $item['COLUMN_NAME'];
            if ($this->ucfirst === 'true') {
                $columnName = $this->upper($columnName);
            }
            $isNullable = $item['IS_NULLABLE'];
            $columnDefault = str_replace(['(', ')', '\'', '"'], '', $item['COLUMN_DEFAULT']);
            $characterMaximumLength = $item['CHARACTER_MAXIMUM_LENGTH'];
            $numericPrecision = $item['NUMERIC_PRECISION'];
            $numericScale = $item['NUMERIC_SCALE'];
            $columnComment = $columnCommentMap[$item['COLUMN_NAME']] ?? "";
            $isPrimaryKey = (isset($primaryKeyMap[$item['COLUMN_NAME']]));
            $isAutoIncrement = ($isPrimaryKey ? ($primaryKeyMap[$item['COLUMN_NAME']]['IsIdentity'] == '1') : false);
            $nullable = "";
            $nullableType = "";
            if ($isNullable === "YES") {
                $nullable = "nullable: true";
                $nullableType = "?";
            } else {
                if ($type === 'json') {
                    $columnDefault = "[]";
                }
            }
            $varType = match ($type) {
                "bigint", "decimal", "numeric", "varchar", "char", 'nchar', 'nvarchar', 'uniqueidentifier' => "string",
                "int", "smallint", "smallmoney", "tinyint", "money" => "int",
                "float", 'real' => "float",
                "bit" => "bool",
                "date", "time", "datetime", "datetime2", "smalldatetime", "datetimeoffset" => "\DateTimeInterface",
                default => $type,
            };
            $ormColumnParam = [];
            if ($this->ucfirst === 'true') {
                $ormColumnParam[] = "name: \"{$item['COLUMN_NAME']}\"";
            }
            $ormColumnOptionParam = [];
            if(in_array($type, ["int", "smallint", "tinyint", "bigint", "smallmoney", "money", "float", "real", "numeric", "decimal", "char", "bit",
                "varchar", "varbinary", "binary", "nchar", "nvarchar", "uniqueidentifier", "date", "time", "datetime", "datetime2", "datetimeoffset", "smalldatetime"])){
                if ($type === 'smallint') {
                    $ormColumnParam[] = "type: Types::SMALLINT";
                } else if ($type === 'bigint') {
                    $ormColumnParam[] = "type: Types::BIGINT";
                } else if (in_array($type, ['decimal', 'numeric'])) {
                    $ormColumnParam[] = "type: Types::DECIMAL";
                    $ormColumnParam[] = "precision: $numericPrecision";
                    $ormColumnParam[] = "scale: $numericScale";
                } else if (in_array($type, ['binary', 'varbinary'])) {
                    $ormColumnParam[] = "type: Types::BINARY";
                }else if($type === 'uniqueidentifier'){
                    $ormColumnParam[] = "type: Types::GUID";
                }else if($type === 'date'){
                    $ormColumnParam[] = "type: Types::DATE_MUTABLE";
                }else if($type === 'time'){
                    $ormColumnParam[] = "type: Types::TIME_MUTABLE";
                }else if (in_array($type, ['datetime', 'datetime2', 'datetimeoffset', 'smalldatetime'])) {
                    $ormColumnParam[] = "type: Types::DATETIME_MUTABLE";
                }
                if (in_array($type, ['char', 'varchar', 'nchar', 'nvarchar'])) {
                    $ormColumnParam[] = "length: {$characterMaximumLength}";
                }
                if (!empty($nullable)) {
                    $ormColumnParam[] = $nullable;
                }
                if (!empty($columnComment)) {
                    $ormColumnOptionParam[] = "\"comment\" => \"{$columnComment}\"";
                }
                if (in_array($type, ["char", "nchar"])) {
                    $ormColumnOptionParam[] = "\"fixed\" => true";
                }
                if (!empty($ormColumnOptionParam)) {
                    $ormColumnParam[] = "options: [" . implode(', ', $ormColumnOptionParam) . "]";
                }
                if (empty($ormColumnParam)) {
                    $properties .= "    #[ORM\Column]" . PHP_EOL;
                } else {
                    $ormColumnParam = implode(', ', $ormColumnParam);
                    $properties .= "    #[ORM\Column({$ormColumnParam})]" . PHP_EOL;
                }
                if ($isPrimaryKey) {
                    $properties .= "    #[ORM\Id]" . PHP_EOL;
                    if ($isAutoIncrement) {
                        $strategy = "strategy: \"IDENTITY\"";
                    } else {
                        $strategy = "strategy: \"NONE\"";
                    }
                    $properties .= "    #[ORM\GeneratedValue({$strategy})]" . PHP_EOL;
                }
                if (in_array($type, ['binary', 'varbinary'])) {
                    if (!empty($columnDefault)) {
                        $properties .= "    private \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else {
                    if (in_array($type, ['bigint', 'decimal', 'numeric', 'char', 'varchar', 'nchar', 'nvarchar', "uniqueidentifier"])) {
                        if (!empty($columnDefault)) {
                            $columnDefault = "'{$columnDefault}'";
                            $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                        } else {
                            $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                        }
                    } else {
                        if (!empty($columnDefault)) {
                            if (in_array($type, ['date', 'time', 'datetime', 'datetime2', 'datetimeoffset', 'smalldatetime'])) {
                                $columnDefault = 'null';
                            }else if($type === 'bit'){
                                $columnDefault = ($columnDefault != "0" ? "true" : "false");
                            }
                            $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                        } else {
                            $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                        }
                    }
                }
            }
            $functionName = $this->upperName($columnName);
            if(in_array($type, ["int", "smallint", "tinyint", "bigint", "smallmoney", "money", "float", "real", "numeric", "decimal", "char", "bit",
                "varchar", "nchar", "uniqueidentifier", "nvarchar", "date", "time", "datetime", "datetime2", "datetimeoffset", "smalldatetime"])){
                $getSet .= "    public function get{$functionName}(): ?{$varType}" . PHP_EOL;
                $getSet .= "    {" . PHP_EOL;
                $getSet .= "        return \$this->{$columnName};" . PHP_EOL;
                $getSet .= "    }" . PHP_EOL . PHP_EOL;
                if (!$isAutoIncrement) {
                    $getSet .= "    public function set{$functionName}({$nullableType}{$varType} \${$columnName}): static" . PHP_EOL;
                    $getSet .= "    {" . PHP_EOL;
                    $getSet .= "        \$this->{$columnName} = \${$columnName};" . PHP_EOL;
                    $getSet .= PHP_EOL;
                    $getSet .= "        return \$this;" . PHP_EOL;
                    $getSet .= "    }" . PHP_EOL . PHP_EOL;
                }
            }
            if(in_array($type, ["varbinary", "binary"])){
                $getSet .= "    public function get{$functionName}()" . PHP_EOL;
                $getSet .= "    {" . PHP_EOL;
                $getSet .= "        return \$this->{$columnName};" . PHP_EOL;
                $getSet .= "    }" . PHP_EOL . PHP_EOL;
                $getSet .= "    public function set{$functionName}(\${$columnName}): static" . PHP_EOL;
                $getSet .= "    {" . PHP_EOL;
                $getSet .= "        \$this->{$columnName} = \${$columnName};" . PHP_EOL;
                $getSet .= PHP_EOL;
                $getSet .= "        return \$this;" . PHP_EOL;
                $getSet .= "    }" . PHP_EOL . PHP_EOL;
            }
        }
        return [rtrim($properties), rtrim($getSet)];
    }
}