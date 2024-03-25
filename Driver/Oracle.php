<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

class Oracle extends Driver
{
    public function getTableList(): array
    {
        $rs = $this->connection->fetchAllAssociative("SELECT TABLE_NAME FROM sys.user_tables");
        return array_column($rs, 'TABLE_NAME');
    }

    public function getTableInfo(string $tableName = ""): array
    {
        $sql = <<<EOF
SELECT   c.*,
         d.comments
FROM     all_tab_columns c
inner join all_col_comments d
on d.TABLE_NAME = c.TABLE_NAME AND d.OWNER = c.OWNER
    AND    d.COLUMN_NAME = c.COLUMN_NAME AND d.OWNER = c.OWNER
WHERE    c.table_name = '{$tableName}'
ORDER BY c.column_id
EOF;
        return $this->connection->fetchAllAssociative($sql);
    }

    public function getKeyIndexes(): array
    {
        $sql = <<<EOF
SELECT uind_col.index_name AS name,
       (
           SELECT uind.index_type
           FROM   user_indexes uind
           WHERE  uind.index_name = uind_col.index_name
       ) AS type,
       decode(
               (
                   SELECT uind.uniqueness
                   FROM   user_indexes uind
                   WHERE  uind.index_name = uind_col.index_name
               ),
               'NONUNIQUE',
               0,
               'UNIQUE',
               1
       ) AS is_unique,
       uind_col.column_name AS column_name,
       uind_col.column_position AS column_pos,
       (
           SELECT ucon.constraint_type
           FROM   user_constraints ucon
           WHERE  ucon.index_name = uind_col.index_name
             AND  ucon.table_name = uind_col.table_name
       ) AS is_primary
FROM      user_ind_columns uind_col
WHERE     uind_col.table_name = '{$this->tableName}'
ORDER BY  uind_col.column_position ASC
EOF;
        return $this->connection->fetchAllAssociative($sql);
    }

    public function makeIndexes(): string
    {

        $rs = $this->getKeyIndexes();
        $rs = array_filter($rs, function($v){
            return $v['IS_PRIMARY'] !== 'P';
        });
        if (empty($rs)) {
            return "";
        }
        $indexes = [];
        $indexArray = [];
        foreach ($rs as $r) {
            if (isset($indexArray[$r['NAME']])) {
                $indexArray[$r['NAME']]['Column_name'][] = $r['COLUMN_NAME'];
            } else {
                $indexArray[$r['NAME']] = [
                    'Non_unique' => 1 - $r['IS_UNIQUE'], // $r['IS_UNIQUE'] == '1' ? '0' : '1'
                    'Column_name' => [$r['COLUMN_NAME']]
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

    private function getPrimaryKey(): array
    {
        $rs = $this->getKeyIndexes();
        $rs = array_filter($rs, function($v){
            return $v['IS_PRIMARY'] === 'P';
        });
        return array_column($rs, 'COLUMN_NAME');
    }

    private function getSequence(): array
    {
        $sql = "SELECT * FROM sys.USER_SEQUENCES";
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, null, 'SEQUENCE_NAME');
    }

    public function makeProperties(): array
    {
        $properties = "";
        $getSet = "";
        $sequenceMap = $this->getSequence();
        $primaryColumn = $this->getPrimaryKey();
        $primaryArray = array_filter($this->tableInfo, function ($v) use ($primaryColumn) {
            return in_array($v['COLUMN_NAME'], $primaryColumn);
        });
        $othersArray = array_filter($this->tableInfo, function ($v) use ($primaryColumn) {
            return !in_array($v['COLUMN_NAME'], $primaryColumn);
        });
        $this->tableInfo = [];
        array_push($this->tableInfo, ...$primaryArray, ...$othersArray);
        foreach ($this->tableInfo as $item) {
            $type = $item['DATA_TYPE'];
            $columnName = $item['COLUMN_NAME'];
            if ($this->ucfirst === 'true') {
                $columnName = $this->upper($columnName);
            }
            $isNullable = $item['NULLABLE'];
            $columnDefault = $item['DATA_DEFAULT'];
            $characterMaximumLength = $item['CHAR_LENGTH'];
            $numericPrecision = $item['DATA_PRECISION'] ?? 10;
            $numericScale = $item['DATA_SCALE'] ?? 0;
            $columnComment = $item['COMMENTS'];
            $isPrimaryKey = in_array($item['COLUMN_NAME'], $primaryColumn);
            $sequenceKey = str_replace(["\"{$this->database}\".\"","\".nextval"],"",$item['DATA_DEFAULT']);
            $isAutoIncrement = isset($sequenceMap[$sequenceKey]);
            $nullable = "";
            $nullableType = "";
            $ormColumnParam = [];
            $ormColumnOptionParam = [];
            if ($isNullable === "Y") {
                $nullable = "nullable: true";
                $nullableType = "?";
            }
            if ($this->ucfirst === 'true') {
                $ormColumnParam[] = "name: \"{$item['COLUMN_NAME']}\"";
            }
            $varType = $type;
            if(in_array($type, ["CHAR", "NCHAR", "VARCHAR2", "VARCHAR", "NVARCHAR2", 'CLOB', 'NCLOB', 'BLOB', 'UROWID'])){
                $varType = "string";
            }else if($type === 'NUMBER'){
                $varType = "int";
            }else if(in_array($type, ["FLOAT", "BINARY_FLOAT", "BINARY_DOUBLE"])){
                $varType = "float";
            }else if(str_starts_with($type, "TIMESTAMP") || $type == "DATE"){
                $varType = "\DateTimeInterface";
            }
            if(in_array($type, ["CHAR", "NCHAR", "VARCHAR2", "VARCHAR", "NVARCHAR2", 'NUMBER', "FLOAT", "BINARY_FLOAT", "BINARY_DOUBLE", 'CLOB', 'NCLOB', 'BLOB', 'UROWID', "DATE", "RAW"]) || str_starts_with($type, "TIMESTAMP")){
                if (in_array($type, ["FLOAT", "BINARY_FLOAT", "BINARY_DOUBLE"])) {
                    $ormColumnParam[] = "type: Types::FLOAT";
                    $ormColumnParam[] = "precision: $numericPrecision";
                    $ormColumnParam[] = "scale: $numericScale";
                }else if(str_starts_with($type, "TIMESTAMP") || $type == "DATE"){
                    if($type == "DATE"){
                        $ormColumnParam[] = "type: Types::DATE_MUTABLE";
                    } else if(str_ends_with($type, "TIME ZONE")){
                        $ormColumnParam[] = "type: Types::DATETIMETZ_IMMUTABLE";
                    }else{
                        $ormColumnParam[] = "type: Types::DATETIME_MUTABLE";
                    }
                }else if($type == 'RAW'){
                    $ormColumnParam[] = "type: Types::BINARY";
                }
                if (in_array($type, ["CHAR", "NCHAR", "VARCHAR2", "VARCHAR", "NVARCHAR2"])) {
                    $ormColumnParam[] = "length: {$characterMaximumLength}";
                }
                if (!empty($nullable)) {
                    $ormColumnParam[] = $nullable;
                }
                if (!empty($columnComment)) {
                    $ormColumnOptionParam[] = "\"comment\" => \"{$columnComment}\"";
                }
                if (in_array($type, ['CHAR', 'NCHAR'])) {
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
                    if ($isAutoIncrement) {
                        $sequenceName = str_replace('$',"\\$", $sequenceMap[$sequenceKey]['SEQUENCE_NAME']);
                        $allocationSize = $sequenceMap[$sequenceKey]['INCREMENT_BY'];
                        $initialValue = $sequenceMap[$sequenceKey]['MIN_VALUE'];
                        $properties .= "    #[ORM\SequenceGenerator(sequenceName: \"{$sequenceName}\", allocationSize: {$allocationSize}, initialValue: {$initialValue})]" . PHP_EOL;
                    }
                }
                if($type == 'RAW'){
                    if (isset($columnDefault)) {
                        $properties .= "    private \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                }else if (in_array($type, ['NUMBER', "FLOAT", "BINARY_FLOAT", "BINARY_DOUBLE"])) {
                    if (isset($columnDefault) && !$isAutoIncrement) {
                        $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else if(str_starts_with($type, "TIMESTAMP") || $type == "DATE"){
                    $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                }else{
                    if (isset($columnDefault)) {
                        $properties .= "    private ?{$varType} \${$columnName} = '{$columnDefault}';" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                }
            }
            $functionName = $this->upperName($item['COLUMN_NAME']);
            if(in_array($type, ["CHAR", "NCHAR", "VARCHAR2", "VARCHAR", "NVARCHAR2", 'NUMBER', "FLOAT", "BINARY_FLOAT", "BINARY_DOUBLE"]) || str_starts_with($type, "TIMESTAMP")){
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
            if(in_array($type, ["RAW"])){
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