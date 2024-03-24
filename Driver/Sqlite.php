<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

class Sqlite extends Driver
{
    public function getTableList(): array
    {
        $sql = <<<EOF
SELECT name FROM sqlite_master
WHERE type = 'table'
AND name != 'sqlite_sequence'
AND name != 'geometry_columns'
AND name != 'spatial_ref_sys'
UNION ALL SELECT name FROM sqlite_temp_master
WHERE type = 'table' ORDER BY name
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, 'name');
    }

    public function getTableInfo(string $tableName = "")
    {
        return $this->connection->fetchAllAssociative("PRAGMA table_info('{$tableName}')");
    }

    public function makeIndexes()
    {

        $rs = $this->connection->fetchAllAssociative("SELECT * FROM sqlite_master where type = 'index' and tbl_name = '{$this->tableName}'");
        $indexInfoMap = array_column($rs, null, 'name');
        $rs1 = $this->connection->fetchAllAssociative("PRAGMA index_list('{$this->tableName}')");
        $indexMap = array_column($rs1, null, 'name');
        $indexArray = [];
        foreach ($indexMap as $k => $item) {
            $createIndexSQL = $indexInfoMap[$k]['sql'];
            $indexOption = $this->getTypeOption($createIndexSQL);
            $indexArray[$k] = [
                'Non_unique' => 1 - $item['unique'],
                'Column_name' => explode(',', $indexOption[1])
            ];
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

    public function getCreateSQL(): string
    {
        $rs = $this->connection->fetchAllAssociative("SELECT * FROM sqlite_master where type = 'table' and name = '{$this->tableName}'");
        return $rs[0]['sql'];
    }

    public function makeProperties(): array
    {
        $createSQL = $this->getCreateSQL();
        $properties = "";
        $getSet = "";
        $primaryArray = array_filter($this->tableInfo,function($v){
            return $v['pk'] == '1';
        });
        $othersArray = array_filter($this->tableInfo,function($v){
            return $v['pk'] != '1';
        });
        $this->tableInfo = [];
        array_push($this->tableInfo, ...$primaryArray, ...$othersArray);
        foreach ($this->tableInfo as $item) {
            [$type, $numOption] = $this->getTypeOption($item['type']);
            $columnName = $item['name'];
            if ($this->ucfirst === 'true') {
                $columnName = $this->upper($columnName);
            }
            $isNullable = $item['notnull'];
            $columnDefault = $item['dflt_value'];
            $isPrimaryKey = ($item['pk'] == '1');
            $isAutoIncrement = $this->isAutoIncrement($createSQL, $item['name']);
            $nullable = "";
            $nullableType = "";
            if ($isNullable === "YES") {
                $nullable = "nullable: true";
                $nullableType = "?";
            }
            $varType = match ($type) {
                "bigint", "decimal", "varchar", "nvarchar", "text", "clob", "blob" => "string",
                "smallint", "tinyint", "mediumint" ,"int" , "integer" => "int",
                "double", "float", "real", "double precision" => "float",
                "date", "datetime" => "\DateTimeInterface",
                "boolean" => 'bool',
                default => $type,
            };
            $ormColumnParam = [];
            if ($this->ucfirst === 'true') {
                $ormColumnParam[] = "name: \"{$item['name']}\"";
            }
            $ormColumnOptionParam = [];
            if(in_array($type, ["int", "integer", "smallint", "mediumint", "bigint", "real", "float", "double", "double precision", "decimal",
                "varchar", "nvarchar", "blob", "clob", "text", "date", "datetime", "boolean"])){
                if ($type === 'smallint') {
                    $ormColumnParam[] = "type: Types::SMALLINT";
                } else if ($type === 'bigint') {
                    $ormColumnParam[] = "type: Types::BIGINT";
                } else if ($type === 'decimal') {
                    $ormColumnParam[] = "type: Types::DECIMAL";
                    $ormColumnParam[] = "precision: 10";
                    $ormColumnParam[] = "scale: 0";
                }else if ($type === 'blob') {
                    $ormColumnParam[] = "type: Types::BLOB";
                } else if ($type === 'text' || $type === 'clob') {
                    $ormColumnParam[] = "type: Types::TEXT";
                }else if($type === 'date'){
                    $ormColumnParam[] = "type: Types::DATE_MUTABLE";
                }else if ($type === 'datetime') {
                    $ormColumnParam[] = "type: Types::DATETIME_MUTABLE";
                }
                if (in_array($type, ['nvarchar', 'varchar'])) {
                    $characterMaximumLength = $numOption;
                    $ormColumnParam[] = "length: {$characterMaximumLength}";
                }
                if (!empty($nullable)) {
                    $ormColumnParam[] = $nullable;
                }
                if (!empty($columnComment)) {
                    $ormColumnOptionParam[] = "\"comment\" => \"{$columnComment}\"";
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
                if (in_array($type, ['clob', 'decimal', 'nvarchar', 'varchar', 'blob'])) {
                    if (isset($columnDefault)) {
                        $columnDefault = "'{$columnDefault}'";
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else {
                    if (isset($columnDefault)) {
                        if (in_array($type, ['date', 'datetime', 'text'])) {
                            $columnDefault = 'null';
                        }
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                }
            }
            $functionName = $this->upperName($columnName);
            if(in_array($type, ["int", "integer", "smallint", "mediumint", "bigint", "real", "float", "double", "double precision", "decimal",
                "varchar", "nvarchar", "blob", "clob", "text", "date", "datetime", "boolean"])){
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
        }
        return [rtrim($properties), rtrim($getSet)];
    }

    private function getTypeOption(string $type): array
    {
        $type = strtolower($type);
        $strType = $type;
        $options = 0;
        if(str_contains($type, "(")){
            $first = strpos($type, "(");
            $strType = substr($type, 0, $first);
            $options = str_replace([")"], "", substr($type, $first + 1));
        }
        return [$strType, $options];
    }

    private function isAutoIncrement(string $sql, string $name): bool
    {
        $sqlArray = explode("\n", $sql);
        foreach ($sqlArray as $index => &$item) {
            $item = trim(strtolower($item));
            if(str_contains($item, 'autoincrement')){
                if(str_starts_with($item, $name)){
                    return true;
                }else if(str_starts_with($sqlArray[$index - 1], $name)) {
                    return true;
                }else if(str_starts_with($sqlArray[$index - 2], $name)) {
                    return true;
                }
            }
        }
        return false;
    }
}