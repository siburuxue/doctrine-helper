# Doctrine Helper 
English | [Chinese](README_zh.md)

This tool provides an alternative to the `php bin/console doctrine:mapping:import App\\Entity attribute --path=src/Entity` command in Symfony 7, as the `doctrine:mapping:import` command has been removed in the latest versions. It allows for importing entity mappings from existing databases into Symfony applications.

## Supported Databases

- MySQL
- PostgreSQL
- SQLServer
- Oracle
- Sqlite3

## Supported Field Types

### MySQL
- Does not support `bit` and `enum` types
- Converts similar types (e.g., `double` to `float`)

### PostgreSQL
- Supports numeric, string, date, currency, JSON, UUID, binary, and boolean types
- Does not support complex or custom types

### SQLServer
- Supports exact numeric, approximate numeric, date/time, string (char, varchar), Unicode string (nchar, nvarchar), binary string (binary, varbinary), and other data types
- Does not support deprecated or complex data types

### Oracle
- Supports various data types such as NUMBER, FLOAT, CHAR, VARCHAR2, DATE, TIMESTAMP, RAW, CLOB, BLOB, etc.
- Does not support other data types

### Sqlite
- Supports integer, real, float, double, decimal, varchar, blob, text, date, datetime, and boolean types

## Install
```shell
composer require siburuxue/doctrine-helper
```

## Synchronize database tables to the project
```shell
php bin/console doctrine-helper:mapping:import App\\Entity attribute --path=src/Entity --ucfrist=true --table=dict,log --without-table-prefix=eq_
php bin/console doctrine-helper:mapping:import --ucfirst=true
```

## Command Line Options

- `namespace`: The namespace for the Entity class (default: `App\Entity`)
- `type`: Type of database field description (attribute, xml, yaml, php) (default: `attribute`)
- `--path`: Path to store Entity class files (default: `src/Entity`)
- `--ucfirst=true`: Generate Symfony 6 style Entities (private properties in camelCase) for seamless code migration (default: Symfony 7 style with underscored private properties)
- `--table`: Import specific tables to generate corresponding Entity and Repository classes
- `--without-table-prefix`: Ignore table prefix when generating Entities

Feel free to explore and utilize this tool for managing Doctrine mappings in your Symfony applications!

## Contact Us(QQ)
<img src="./QQ.jpg" width="400px"/>