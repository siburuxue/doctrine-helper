<img src="./title.png" width="auto"/>

[英文](README.md) | 中文

该工具提供了在Symfony 7中替代`php bin/console doctrine:mapping:import App\\Entity attribute --path=src/Entity`命令的选择，因为在最新版本中移除了`doctrine:mapping:import`命令。它允许将现有数据库中的实体映射导入Symfony应用程序。

## 支持的数据库

- MySQL
- PostgreSQL
- SQLServer
- Oracle
- Sqlite3

## 支持的字段类型

### MySQL
- 不支持`bit`类型
- 在支持`enum`类型时，需要设置 [mapping_types](https://symfony.com/doc/current/doctrine/dbal.html#registering-custom-mapping-types-in-the-schematool)
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

## 安装
```shell
composer require siburuxue/doctrine-helper
```

## 同步数据库
```shell
php bin/console doctrine-helper:mapping:import App\\Entity attribute --path=src/Entity --ucfrist=true --table=dict,log --without-table-prefix=eq_
php bin/console doctrine-helper:mapping:import --ucfirst=true
```

## 命令行选项

- `namespace`：实体类的命名空间（默认为`App\Entity`）
- `type`：数据库字段描述信息类型（attribute、xml、yaml、php）（默认为`attribute`，目前只支持`attribute`）
- `--path`：存储实体类文件的路径（默认为`src/Entity`）
- `--ucfirst=true`：生成Symfony 6风格的实体类（私有属性使用驼峰命名规则），以实现代码无缝迁移（默认为Symfony 7风格，私有属性使用下划线）
- `--table`：导入指定表以生成相应的实体类和存储库类
- `--without-table-prefix`：生成实体类时忽略表前缀
- `--schema`：在链接postgresql数据库时，同步指定schema的表（仅postgresql数据库可用）

本文以MySQL为例，展示生成Entity结果。

假设您的数据库中包含test表，它几乎包含了MySQL所有的数据类型,并且添加了唯一索引，联合索引和普通索引：

```sql
create table test
(
    id     int                                     not null,
    int_1  int                 default 1           not null comment 'int',
    int_2  smallint            default 2           null comment 'smallint',
    int_3  int                 default 3           null comment 'tinyint',
    int_4  mediumint           default 4           null comment 'mediumint',
    int_5  bigint                                  not null comment 'bigint'
        primary key,
    int_6  double              default 6           null comment 'float',
    int_7  double              default 7           null comment 'double',
    int_8  decimal             default 8           null comment 'decimal',
    date_1 date                default (curdate()) null comment 'date',
    date_2 time                default (curtime()) null comment 'time',
    date_3 datetime            default (now())     null comment 'datetime',
    date_4 timestamp           default (now())     null comment 'timestamp',
    date_5 year                default (now())     null comment 'year',
    str_1  char                default 'a'         null comment 'char',
    str_2  varchar(255)        default 'b'         null comment 'varchar(255)',
    str_3  varbinary(1)        default 0x63        null comment 'binary',
    str_4  varbinary(1)        default 0x64        null comment 'varbinary(1)',
    str_8  set ('a', 'b', 'c') default 'a,b'       null comment 'set',
    json_1 json                                    null comment 'json',
    bool_1 int                 default 0           null comment 'bool',
    constraint I_int_2
        unique (int_2) comment '唯一索引'
)
    comment '测试表';

create index I_int_1
    on test (int_1)
    comment '普通索引';

create index I_int_3
    on test (int_3, int_4, int_5)
    comment '联合索引';

create index I_int_4
    on test (int_6);
```

通过命令来生成对应的Entity类文件：
```shell
php bin/console doctrine-helper:mapping:import --ucfirst=true --table=test
```

新创建的Test实体类如下所示：
```php
// src/Entity/Test.php
namespace App\Entity;

use App\Repository\TestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'test')]
#[ORM\UniqueConstraint(name: 'I_int_2', columns: ['int_2'])]
#[ORM\Index(name: 'I_int_1', columns: ['int_1'])]
#[ORM\Index(name: 'I_int_3', columns: ['int_3', 'int_4', 'int_5'])]
#[ORM\Index(name: 'I_int_4', columns: ['int_6'])]
#[ORM\Entity(repositoryClass: TestRepository::class)]
class Test
{
    #[ORM\Column(name: "int_5", type: Types::BIGINT, options: ["comment" => "bigint"])]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "NONE")]
    private ?string $int5 = null;

    #[ORM\Column(name: "id")]
    private ?int $id = null;

    #[ORM\Column(name: "int_1", options: ["comment" => "int", "default" => 1])]
    private ?int $int1 = 1;

    #[ORM\Column(name: "int_2", type: Types::SMALLINT, nullable: true, options: ["comment" => "smallint", "default" => 2])]
    private ?int $int2 = 2;

    #[ORM\Column(name: "int_3", nullable: true, options: ["comment" => "tinyint", "default" => 3])]
    private ?int $int3 = 3;

    #[ORM\Column(name: "int_4", nullable: true, options: ["comment" => "mediumint", "default" => 4])]
    private ?int $int4 = 4;

    #[ORM\Column(name: "int_6", nullable: true, options: ["comment" => "float", "default" => 6])]
    private ?float $int6 = 6;

    #[ORM\Column(name: "int_7", nullable: true, options: ["comment" => "double", "default" => 7])]
    private ?float $int7 = 7;

    #[ORM\Column(name: "int_8", type: Types::DECIMAL, precision: 10, scale: 0, nullable: true, options: ["comment" => "decimal", "default" => 8])]
    private ?string $int8 = '8';

    #[ORM\Column(name: "date_1", type: Types::DATE_MUTABLE, nullable: true, options: ["comment" => "date", "default" => 'curdate()'])]
    private ?\DateTimeInterface $date1 = null;

    #[ORM\Column(name: "date_2", type: Types::TIME_MUTABLE, nullable: true, options: ["comment" => "time", "default" => 'curtime()'])]
    private ?\DateTimeInterface $date2 = null;

    #[ORM\Column(name: "date_3", type: Types::DATETIME_MUTABLE, nullable: true, options: ["comment" => "datetime", "default" => 'now()'])]
    private ?\DateTimeInterface $date3 = null;

    #[ORM\Column(name: "date_4", type: Types::DATETIME_MUTABLE, nullable: true, options: ["comment" => "timestamp", "default" => 'now()'])]
    private ?\DateTimeInterface $date4 = null;

    #[ORM\Column(name: "date_5", type: Types::DATETIME_MUTABLE, nullable: true, options: ["comment" => "year", "default" => 'now()'])]
    private ?\DateTimeInterface $date5 = null;

    #[ORM\Column(name: "str_1", length: 1, nullable: true, options: ["comment" => "char", "fixed" => true, "default" => 'a'])]
    private ?string $str1 = 'a';

    #[ORM\Column(name: "str_2", length: 255, nullable: true, options: ["comment" => "varchar(255)", "default" => 'b'])]
    private ?string $str2 = 'b';

    #[ORM\Column(name: "str_3", type: Types::BINARY, length: 1, nullable: true, options: ["comment" => "binary", "default" => '0x63'])]
    private $str3 = 0x63;

    #[ORM\Column(name: "str_4", type: Types::BINARY, length: 1, nullable: true, options: ["comment" => "varbinary(1)", "default" => '0x64'])]
    private $str4 = 0x64;

    #[ORM\Column(name: "str_8", type: Types::SIMPLE_ARRAY, nullable: true, options: ["comment" => "set", "default" => 'a,b'])]
    private ?array $str8 = ["a","b"];

    #[ORM\Column(name: "json_1", nullable: true, options: ["comment" => "json"])]
    private ?array $json1 = null;

    #[ORM\Column(name: "bool_1", nullable: true, options: ["comment" => "bool", "default" => 0])]
    private ?int $bool1 = 0;

    // ...getter and setter
}
```

欢迎探索并利用此工具来管理Symfony应用程序中的Doctrine映射！

## 联系我们（QQ）
<img src="./QQ.jpg" width="400px"/>