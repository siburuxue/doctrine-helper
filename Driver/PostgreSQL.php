<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

class PostgreSQL extends Driver
{
    public function getTableList(): array
    {
        $rs = $this->connection->fetchAllAssociative("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
        return array_column($rs, 'tablename');
    }

    public function getTableInfo(string $tableName = ""): array
    {
        $sql = "SELECT * FROM information_schema.columns WHERE table_name = '{$tableName}'";
        return $this->connection->fetchAllAssociative($sql);
    }

    public function makeIndexes(): string
    {
        $sql = <<<EOF
select
    t.relname as table_name,
    i.relname as index_name,
    ix.indisunique,
    a.attname as column_name
from
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a
where
    t.oid = ix.indrelid
  and i.oid = ix.indexrelid
  and a.attrelid = t.oid
  and a.attnum = ANY(ix.indkey)
  and t.relkind = 'r'
  and t.relname = '{$this->tableName}'
  and ix.indisprimary <> true
order by
    t.relname,
    i.relname;
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        if (empty($rs)) {
            return "";
        }
        $indexes = [];
        $indexArray = [];
        foreach ($rs as $r) {
            if (isset($indexArray[$r['index_name']])) {
                $indexArray[$r['index_name']]['Column_name'][] = $r['column_name'];
            } else {
                $indexArray[$r['index_name']] = [
                    'Non_unique' => $r['indisunique'] ? "0" : "1",
                    'Column_name' => [$r['column_name']]
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
        $sql = <<<EOF
select
    t.relname as table_name,
    i.relname as index_name,
    ix.indisunique,
    a.attname as column_name
from
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a
where
    t.oid = ix.indrelid
  and i.oid = ix.indexrelid
  and a.attrelid = t.oid
  and a.attnum = ANY(ix.indkey)
  and t.relkind = 'r'
  and t.relname = '{$this->tableName}'
  and ix.indisprimary = true
order by
    t.relname,
    i.relname;
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, 'column_name');
    }

    private function getSequence(): array
    {
        $sql = <<<EOF
select ts.nspname as object_schema,
       tbl.relname as table_name,
       col.attname as column_name,
       s.relname   as sequence_name,
       pg_sequences.start_value,
       pg_sequences.increment_by
from pg_class s
         join pg_namespace sn on sn.oid = s.relnamespace
         join pg_depend d on d.refobjid = s.oid and d.refclassid='pg_class'::regclass
         join pg_attrdef ad on ad.oid = d.objid and d.classid = 'pg_attrdef'::regclass
         join pg_attribute col on col.attrelid = ad.adrelid and col.attnum = ad.adnum
         join pg_class tbl on tbl.oid = ad.adrelid
         join pg_namespace ts on ts.oid = tbl.relnamespace
         join pg_sequences on s.relname = pg_sequences.sequencename
where s.relkind = 'S'
  and d.deptype in ('a', 'n')
  and ts.nspname = 'public'
  and tbl.relname = '{$this->tableName}'
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, null, 'column_name');
    }

    public function getColumnComment(): array
    {
        $sql = <<<EOF
select
       a.attname AS column_name,
       d.description AS comment
from pg_class c, pg_attribute a , pg_type t, pg_description d
where  c.relname = '{$this->tableName}'
  and a.attnum>0
  and a.attrelid = c.oid
  and a.atttypid = t.oid
  and  d.objoid=a.attrelid
  and d.objsubid=a.attnum
ORDER BY c.relname DESC,a.attnum ASC
EOF;
        $rs = $this->connection->fetchAllAssociative($sql);
        return array_column($rs, 'comment', 'column_name');
    }

    public function makeProperties(): array
    {
        $properties = "";
        $getSet = "";
        $sequenceMap = $this->getSequence();
        $primaryColumn = $this->getPrimaryKey();
        $columnCommentMap = $this->getColumnComment();
        $primaryArray = array_filter($this->tableInfo, function ($v) use ($primaryColumn) {
            return in_array($v['column_name'], $primaryColumn);
        });
        $othersArray = array_filter($this->tableInfo, function ($v) use ($primaryColumn) {
            return !in_array($v['column_name'], $primaryColumn);
        });
        $this->tableInfo = [];
        array_push($this->tableInfo, ...$primaryArray, ...$othersArray);
        foreach ($this->tableInfo as $item) {
            $type = $item['data_type'];
            $columnName = $item['column_name'];
            if ($this->ucfirst === 'true') {
                $columnName = $this->upper($columnName);
            }
            $isNullable = $item['is_nullable'];
            $columnDefault = $item['column_default'];
            $characterMaximumLength = $item['character_maximum_length'];
            $numericPrecision = $item['numeric_precision'] ?? 10;
            $numericScale = $item['numeric_scale'] ?? 0;
            $columnComment = $columnCommentMap[$item['column_name']] ?? "";
            $isPrimaryKey = in_array($item['column_name'], $primaryColumn);
            $isAutoIncrement = isset($sequenceMap[$item['column_name']]);
            $nullable = "";
            $nullableType = "";
            $ormColumnParam = [];
            $ormColumnOptionParam = [];
            if ($isNullable === "YES") {
                $nullable = "nullable: true";
                $nullableType = "?";
            }
            if ($this->ucfirst === 'true') {
                $ormColumnParam[] = "name: \"{$item['column_name']}\"";
            }
            $varType = match ($type) {
                "bigint", "decimal", "money", "numeric", "varchar", "character varying", "char", "character", "text", "uuid" => "string",
                "smallint", "integer" => "int",
                "double precision", "real", "float" => "float",
                "json" => "array",
                "date", "time without time zone", "time with time zone", "timestamp without time zone", "timestamp with time zone" => "\DateTimeInterface",
                "interval" => "\DateInterval",
                "boolean" => "bool",
                default => $type,
            };
            if(in_array($type, ["integer", "smallint", "bigint", "float", "double precision", "decimal", "money", "numeric", "real",
                "character varying", "character", "char","varchar", "bytea", "text", "json", 'boolean', 'uuid',
                "date", "time without time zone", "time with time zone", "datetime", "timestamp without time zone", "timestamp with time zone", "interval"])){
                if ($type === 'smallint') {
                    $ormColumnParam[] = "type: Types::SMALLINT";
                } else if ($type === 'bigint') {
                    $ormColumnParam[] = "type: Types::BIGINT";
                } else if (in_array($type, ['decimal', 'numeric', 'money'])) {
                    $ormColumnParam[] = "type: Types::DECIMAL";
                    $ormColumnParam[] = "precision: $numericPrecision";
                    $ormColumnParam[] = "scale: $numericScale";
                }else if ($type === 'bytea') {
                    $ormColumnParam[] = "type: Types::BLOB";
                } else if ($type === 'text') {
                    $ormColumnParam[] = "type: Types::TEXT";
                }else if($type === 'date'){
                    $ormColumnParam[] = "type: Types::DATE_MUTABLE";
                }else if(in_array($type, ['time without time zone', 'time with time zone'])){
                    $ormColumnParam[] = "type: Types::TIME_MUTABLE";
                }else if ($type === 'timestamp without time zone') {
                    $ormColumnParam[] = "type: Types::DATETIME_MUTABLE";
                }else if ($type === 'timestamp with time zone') {
                    $ormColumnParam[] = "type: Types::DATETIMETZ_MUTABLE";
                } else if($type === 'uuid'){
                    $ormColumnParam[] = "type: Types::GUID";
                }
                if (in_array($type, ['char', 'varchar', "character varying", "character"])) {
                    $ormColumnParam[] = "length: {$characterMaximumLength}";
                }
                if (!empty($nullable)) {
                    $ormColumnParam[] = $nullable;
                }
                if (!empty($columnComment)) {
                    $ormColumnOptionParam[] = "\"comment\" => \"{$columnComment}\"";
                }
                if (in_array($type, ['char', 'character'])) {
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
                        $sequenceName = $sequenceMap[$item['column_name']]['sequence_name'];
                        $allocationSize = $sequenceMap[$item['column_name']]['increment_by'];
                        $initialValue = $sequenceMap[$item['column_name']]['start_value'];
                        $properties .= "    #[ORM\SequenceGenerator(sequenceName: \"{$sequenceName}\", allocationSize: {$allocationSize}, initialValue: {$initialValue})]" . PHP_EOL;
                    }
                }
                if (in_array($type, ['bytea'])) {
                    if (isset($columnDefault)) {
                        $properties .= "    private \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                    } else {
                        $properties .= "    private \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                    }
                } else {
                    if (in_array($type, ['bigint', 'decimal', "money", 'numeric', 'char', 'varchar', "character varying", "character", 'uuid'])) {
                        if (isset($columnDefault)) {
                            if(in_array($type, ['bigint', 'decimal', 'numeric'])){
                                if (!is_numeric($columnDefault)){
                                    $columnDefault = 'null';
                                }else{
                                    $columnDefault = "\"{$columnDefault}\"";
                                }
                            }else{
                                $columnDefault = "\"{$columnDefault}\"";
                            }
                            $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                        } else {
                            $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                        }
                    } else {
                        if (isset($columnDefault)) {
                            if (in_array($type, ['date', 'time without time zone', 'time with time zone', 'timestamp without time zone', 'timestamp with time zone', 'interval', 'text'])) {
                                $columnDefault = 'null';
                            }
                            if(in_array($type, ["smallint", "integer", "double precision", "real", "float"])){
                                if(!is_numeric($columnDefault)){
                                    $columnDefault = 'null';
                                }
                            }
                            $properties .= "    private ?{$varType} \${$columnName} = {$columnDefault};" . PHP_EOL . PHP_EOL;
                        } else {
                            $properties .= "    private ?{$varType} \${$columnName} = null;" . PHP_EOL . PHP_EOL;
                        }
                    }
                }
            }
            $functionName = $this->upperName($item['column_name']);
            if(in_array($type, ["integer", "smallint", "bigint", "float", "double precision", "decimal", "money", "numeric", "real", "character varying", "character", "char",
                "varchar", "text", "json", 'boolean', 'uuid',
                "date", "time without time zone", "time with time zone", "timestamp without time zone", "timestamp with time zone", "interval"])){
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
            if(in_array($type, ["bytea"])){
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