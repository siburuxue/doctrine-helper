# Doctrine Helper

该工具提供了在Symfony 7中替代`php bin/console doctrine:mapping:import App\\Entity attribute --path=src/Entity`命令的选择，因为在最新版本中移除了`doctrine:mapping:import`命令。它允许将现有数据库中的实体映射导入Symfony应用程序。

## 支持的数据库

- MySQL
- PostgreSQL
- SQLServer
- Oracle
- Sqlite3

## 支持的字段类型

### MySQL
- 不支持`bit`和`enum`类型
- 将类似类型转换为相似类型（例如，`double`转换为`float`）

### PostgreSQL
- 支持数字、字符串、日期、货币、JSON、UUID、二进制和布尔类型
- 不支持复杂或自定义类型

### SQLServer
- 支持精确数字、近似数字、日期/时间、字符串（char、varchar）、Unicode字符串（nchar、nvarchar）、二进制字符串（binary、varbinary）和其他数据类型
- 不支持弃用或复杂数据类型

### Oracle
- 支持多种数据类型，如NUMBER、FLOAT、CHAR、VARCHAR2、DATE、TIMESTAMP、RAW、CLOB、BLOB等
- 不支持其他数据类型

### Sqlite
- 支持整数、实数、浮点数、双精度、小数、字符、二进制大对象、文本、日期、日期时间和布尔类型

## 命令行选项

- `namespace`：实体类的命名空间（默认为`App\Entity`）
- `type`：数据库字段描述信息类型（attribute、xml、yaml、php）（默认为`attribute`）
- `--path`：存储实体类文件的路径（默认为`src/Entity`）
- `--ucfirst=true`：生成Symfony 6风格的实体类（私有属性使用驼峰命名规则），以实现代码无缝迁移（默认为Symfony 7风格，私有属性使用下划线）
- `--table`：导入指定表以生成相应的实体类和存储库类
- `--without-table-prefix`：生成实体类时忽略表前缀

欢迎探索并利用此工具来管理Symfony应用程序中的Doctrine映射！