<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

class MySQL extends Driver
{
    public function getTableList(): array
    {
        $rs = $this->connection->fetchAllAssociative("show tables");
        return array_column($rs, 'Tables_in_' . $this->database);
    }

    public function getTableInfo(string $tableName = ""): array
    {
        $sql = "select * from information_schema.columns where table_name='{$tableName}' and TABLE_SCHEMA = '{$this->database}' order by ORDINAL_POSITION";
        return $this->connection->fetchAllAssociative($sql);
    }

    public function makeIndexes(): string
    {
        $sql = "show index from {$this->tableName} from {$this->database} where key_name <> 'PRIMARY'";
        $rs = $this->connection->fetchAllAssociative($sql);
        if (empty($rs)) {
            return "";
        }
        $indexes = [];
        $indexArray = [];
        foreach ($rs as $r) {
            if (isset($indexArray[$r['Key_name']])) {
                $indexArray[$r['Key_name']]['Column_name'][] = $r['Column_name'];
            } else {
                $indexArray[$r['Key_name']] = [
                    'Non_unique' => $r['Non_unique'],
                    'Column_name' => [$r['Column_name']]
                ];
            }
        }
        foreach ($indexArray as $key => $item) {
            $class = "ORM\Index";
            if ($item['Non_unique'] == '0') {
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
        $primaryArray = array_filter($this->tableInfo, function ($v) {
            return $v['COLUMN_KEY'] === 'PRI';
        });
        $othersArray = array_filter($this->tableInfo, function ($v) {
            return $v['COLUMN_KEY'] !== 'PRI';
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
            $columnDefault = $item['COLUMN_DEFAULT'];
            $characterMaximumLength = $item['CHARACTER_MAXIMUM_LENGTH'];
            $numericPrecision = $item['NUMERIC_PRECISION'];
            $numericScale = $item['NUMERIC_SCALE'];
            $columnComment = $item['COLUMN_COMMENT'];
            $isPrimaryKey = ($item['COLUMN_KEY'] === 'PRI');
            $isAutoIncrement = ($item['EXTRA'] === 'auto_increment');
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
                "bigint", "decimal", "enum", "varchar", "char", "text", "mediumtext", "longtext" => "string",
                "smallint", "tinyint", "mediumint" => "int",
                "double" => "float",
                "set", "json" => "array",
                "date", "time", "datetime", "timestamp", "year" => "\DateTimeInterface",
                default => $type,
            };
            $ormColumnParam = [];
            if ($this->ucfirst === 'true') {
                $ormColumnParam[] = "name: \"{$item['COLUMN_NAME']}\"";
            }
            $ormColumnOptionParam = [];
            if (in_array($type, ["int", "smallint", "tinyint", "mediumint", "bigint", "float", "double", "decimal", "enum", "char",
                "varchar", "varbinary", "binary", "blob", "mediumblob", "longblob", "text", "mediumtext", "longtext", "set", "json", "date", "time", "datetime", "timestamp", "year"])) {
                if (in_array($type, ['smallint', 'tinyint'])) {
                    $ormColumnParam[] = "type: Types::SMALLINT";
                } else if ($type === 'int') {
                    $ormColumnParam[] = "type: Types::INTEGER";
                } else if ($type === 'bigint') {
                    $ormColumnParam[] = "type: Types::BIGINT";
                } else if ($type === 'decimal') {
                    $ormColumnParam[] = "type: Types::DECIMAL";
                    $ormColumnParam[] = "precision: $numericPrecision";
                    $ormColumnParam[] = "scale: $numericScale";
                } else if (in_array($type, ['binary', 'varbinary'])) {
                    $ormColumnParam[] = "type: Types::BINARY";
                } else if ($type === 'blob' || $type === 'mediumblob' || $type === 'longblob') {
                    $ormColumnParam[] = "type: Types::BLOB";
                } else if ($type === 'text' || $type === 'mediumtext' || $type === 'longtext') {
                    $ormColumnParam[] = "type: Types::TEXT";
                } else if (in_array($type, ['char', 'varchar', 'enum'])) {
                    $ormColumnParam[] = "type: Types::STRING";
                } else if ($type === 'json') {
                    $ormColumnParam[] = "type: Types::JSON";
                } else if ($type === 'set') {
                    $ormColumnParam[] = "type: Types::SIMPLE_ARRAY";
                } else if ($type === 'date') {
                    $ormColumnParam[] = "type: Types::DATE_MUTABLE";
                } else if ($type === 'time') {
                    $ormColumnParam[] = "type: Types::TIME_MUTABLE";
                } else if (in_array($type, ['datetime', 'timestamp', 'year'])) {
                    $ormColumnParam[] = "type: Types::DATETIME_MUTABLE";
                }
                if (in_array($type, ['char', 'varchar', 'binary', 'varbinary'])) {
                    $ormColumnParam[] = "length: {$characterMaximumLength}";
                }
                if (!empty($nullable)) {
                    $ormColumnParam[] = $nullable;
                }
                if (!empty($columnComment)) {
                    $ormColumnOptionParam[] = "\"comment\" => \"{$columnComment}\"";
                }
                if ($type === "char") {
                    $ormColumnOptionParam[] = "\"fixed\" => true";
                }
                if (in_array($type, ["int", "smallint", "tinyint", "mediumint", "bigint", "float", "double", "decimal"])) {
                    if (isset($columnDefault)) {
                        $ormColumnOptionParam[] = "\"default\" => $columnDefault";
                    }
                } else if (in_array($type, ["set", "char", "varchar", 'binary', 'varbinary'])) {
                    if (isset($columnDefault)) {
                        $ormColumnOptionParam[] = "\"default\" => '$columnDefault'";
                    }
                } else if (in_array($type, ["date", "time", "datetime", "timestamp", "year"])) {
                    if (isset($columnDefault)) {
                        $ormColumnOptionParam[] = "\"default\" => '$columnDefault'";
                    }
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
                if (in_array($type, ['binary', 'varbinary', 'blob', 'mediumblob', 'longblob'])) {
                    if (isset($columnDefault)) {
                        $properties .= "    private \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else if (in_array($type, ['bigint', 'decimal', 'enum', 'char', 'varchar'])) {
                    if (isset($columnDefault)) {
                        $columnDefault = "'{$columnDefault}'";
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else if ($type == 'set') {
                    if (isset($columnDefault)) {
                        $columnDefault = json_encode(explode(",", $columnDefault));
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else {
                    if (isset($columnDefault)) {
                        if (in_array($type, ['date', 'time', 'datetime', 'timestamp', 'year', 'text', 'mediumtext', 'longtext'])) {
                            $columnDefault = 'null';
                        }
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                }
            }
            $functionName = $this->upperName($item['COLUMN_NAME']);
            if (in_array($type, ["int", "smallint", "bigint", "tinyint", "mediumint", "float", "double", "decimal", "enum", "char",
                "varchar", "text", "mediumtext", "longtext", "set", "json", "date", "time", "datetime", "timestamp", "year"])) {
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
            if (in_array($type, ["varbinary", "binary", "blob", 'mediumblob', 'longblob'])) {
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