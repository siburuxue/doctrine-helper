# <center>doctrine-helper</center>
instead of `php bin/console doctrine:mapping:import App\\Entity attribute --path=src/Entity

在symfony7版本中默认使用doctrine/orm 3.0版本

在最新版本中删除了 `doctrine:mapping:import` 命令，无法从已经存在的数据库导入生成Entity

目前支持MySQL, PostgreSQL 数据库导入

> 字段支持：
>* 在MySQL的[数据类型](https://dev.mysql.com/doc/refman/8.3/en/data-types.html "数据类型")中，不支持`bit`与`enum`类型，其他数据类型如果有相似类型就转成相似类型，例如：double转成float
>* 在PostgreSQL的[数据类型](http://www.postgres.cn/docs/14/datatype.html)中，仅支持数值类型、字符串类型、日期类型、货币类型、json类型、uuid类型、二进制类型、布尔类型；其他复合类型，自定义类型等均不支持。
>* 在SQLServer(docker 2022:last)的[数据类型](https://learn.microsoft.com/zh-cn/sql/t-sql/data-types/data-types-transact-sql?view=sql-server-ver16)中，支持精确数字、近似数字、日期和时间、字符串（char，varchar）、Unicode 字符串（nchar，nvarchar）、二进制字符串（binary，varbinary）、其他数据类型（uniqueidentifier），其他复合数据类型，被淘汰的数据类型均不支持

>命令行参数：
>* namesapce Entity类的命名空间 默认App\Entity
>* type 数据库字段描述信息 attribute, xml, yaml, php 默认attribute
>* --path=src/Entity Entity类文件存放路径 默认 src/Entity
>* --fcfirst=true 生成symfony6版本Entity（私有属性以驼峰命名规则, 项目迁移业务代码可以无缝衔接）, 默认生成symfony7版本(make:entity生成的Entity中私有属性保留下划线 )
>* --table=test,test1 导入指定表，生成对应的Entity,Repository
>* --without-table-prefix=eq_ 生成Entity时忽略表前缀eq_（eq_test => src/Entity/Test.php）

