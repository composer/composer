# 命令行界面/命令

你已经学会了如何使用命令行界面来做一些事情。本章介绍所有可用的命令。

要从命令行获得帮助，只需调用`composer`或`composer list`查看完整的命令列表，然后再`--help`与其中任何一个命令结合使用即可获得更多信息。

由于Composer使用[symfony/console](https://github.com/symfony/console)，如果不明确，您可以通过短名称来调用命令。

```sh
composer dump
```
调用 `composer dump-autoload`.

## 全局选项

T每个命令都有以下选项可用：

* **--verbose (-v):** 增加消息的冗长度。
* **--help (-h):** 显示帮助信息。
* **--quiet (-q):** 不输出任何消息。
* **--no-interaction (-n):** 不要问任何交互式问题。
* **--no-plugins:** 禁用插件。
* **--working-dir (-d):** 如果指定，则使用给定的目录作为工作目录。
* **--profile:** 显示时间和内存使用信息information
* **--ansi:** 强制ANSI输出。
* **--no-ansi:** 禁用ANSI输出。
* **--version (-V):** 显示此应用程序版本。

## 处理退出代码

* **0:** 好
* **1:** 通用/未知错误代码
* **2:** 依赖性解决错误代码

## 初始化

在[资源库](02-libraries.md)一章中，我们着眼于如何手工创建一个 `composer.json`。还有一个`init`可用的命令使它更容易执行此操作。

当你运行命令时，它会交互式地要求你填写字段，同时使用一些聪明的默认值。

```sh
php composer.phar init
```

### 选项

* **--name:** 包的名称。
* **--description:** 包的描述。
* **--author:** 包的作者姓名。
* **--type:** 包的类型。
* **--homepage:** 包的主页。
* **--require:** 包需要版本限制。应该是格式`foo/bar:1.0.0`。
* **--require-dev:** 开发要求，请参阅 - **要求**。
* **--stability (-s):** 该`minimum-stability`字段的值。
* **--license (-l):** 软件包许可证。
* **--repository:** 提供一个（或多个）自定义存储库。它们将存储在生成的composer.json中，并在提示需求列表时用于自动完成。每个存储库都可以是指向`composer`存储库的HTTP URL 或类似于[存储库](04-schema.md#repositories)密钥所接受的JSON字符串 。

## 安装

该`install`命令从当前目录中读取文件`composer.json`，解析相关性并将其安装到中`vendor`。

```sh
php composer.phar install
```

如果`composer.lock`当前目录中有一个文件，它将使用那里的确切版本而不是解析它们。这确保了使用该库的每个人都将获得相同版本的依赖关系。

如果没有`composer.lock`文件，Composer将在依赖关系解析后创建一个文件。

### 选项

* **--prefer-source:** 有两种下载软件包的方法：`source` 和`dist`。对于稳定版本，Composer将`dist`默认使用该版本。这`source`是一个版本控制库。如果`--prefer-source`启用，Composer将从其安装`source`。如果您想对项目进行错误修正并直接获取依赖项的本地git克隆，这非常有用。

* **--prefer-dist:** 反转`--prefer-source`，`dist`如果可能的话，Composer将会安装。这可以加速大量安装在构建服务器和其他通常不运行供应商更新的用例上。如果你没有正确的设置，这也是一种解决git问题的方法。

* **--dry-run:** 如果你想在没有真正安装包的情况下运行安装，你可以使用`--dry-run`。这将模拟安装并告诉你会发生什么。

* **--dev:** 安装列出的软件包`require-dev`（这是默认行为）。
* **--no-dev:** 跳过安装中列出的软件包`require-dev`。自动装载机生成跳过`autoload-dev`规则。

* **--no-autoloader:** 跳过自动加载器的生成。

* **--no-scripts:** 跳过执行中定义的脚本`composer.json`。

* **--no-progress:** 删除可能会混淆某些不能处理退格字符的终端或脚本的进度显示。

* **--no-suggest:** 在输出中跳过建议的软件包。

* **--optimize-autoloader (-o):** 将PSR-0/4自动加载转换为classmap以获得更快的自动加载器。这是特别推荐用于生产的，但可能需要一些时间才能运行，因此目前尚未默认完成。

* **--classmap-authoritative (-a):** 仅从类映射自动加载类。隐式启用`--optimize-autoloader`。

* **--apcu-autoloader:** 使用APCu来缓存找到/未找到的类。

* **--ignore-platform-reqs:** 忽略`php`，`hhvm`，l`ib-*`和`ext-*` 要求，并强制安装，即使在本地机器不履行这些。另请参阅[platform](06-config.md#platform)配置选项。

## 更新

为了获取最新版本的依赖关系并更新 `composer.lock`文件，您应该使用该`update`命令。这个命令也是别名，`upgrade`因为它的功能与`upgrade`您想要的`apt-get`或类似的软件包管理器相同。

```sh
php composer.phar update
```
这将解决项目的所有依赖关系，并将精确版本写入`composer.lock`。

如果你只想更新一些软件包而不是全部，你可以这样列出它们：

```sh
php composer.phar update vendor/package vendor/package2
```

您也可以使用通配符一次更新一堆包：

```sh
php composer.phar update vendor/*
```

### 选项

* **--prefer-source:** 从`source`可用时安装软件包。

* **--prefer-dist:** 从`dist`可用时安装软件包。

* **--dry-run:** 模拟这个命令，但实际上没有做任何事情。

* **--dev:** 安装列出的软件包`require-dev`（这是默认行为）。

* **--no-dev:** 跳过安装中列出的软件包`require-dev`。自动装载机生成跳过`autoload-dev`规则。

* **--lock:** 只更新锁定文件哈希以取消关于锁定文件过期的警告。

* **--no-autoloader:** 跳过自动加载器的生成。

* **--no-scripts:** 跳过执行中定义的脚本`composer.json`。

* **--no-progress:** 删除可能会混淆某些不能处理退格字符的终端或脚本的进度显示。

* **--no-suggest:** 在输出中跳过建议的软件包。

* **--with-dependencies:** 添加白名单软件包对白名单的依赖性，除了那些是根需求的。

* **--with-all-dependencies:** 将白名单软件包的所有依赖关系添加到白名单中，包括那些是根需求的。

* **--optimize-autoloader (-o):** 将PSR-0/4自动加载转换为classmap以获得更快的自动加载器。这是特别推荐用于生产的，但可能需要一些时间才能运行，因此目前尚未默认完成。

* **--classmap-authoritative (-a):** 仅从类映射自动加载类。隐式启用`--optimize-autoloader`。

* **--apcu-autoloader:** 使用APCu来缓存找到/未找到的类。

* **--ignore-platform-reqs:** 忽略`php`，`hhvm`，`lib-*`和`ext-*` 要求，并强制安装，即使在本地机器不履行这些。另请参阅[`platform`](06-config.md#platform)配置选项。

* **--prefer-stable:** 倾向于稳定版本的依赖关系。

* **--prefer-lowest:** 倾向于最低版本的依赖关系。用于测试最低版本的需求，通常用于`--prefer-stable`。

* **--interactive:** 具有自动完成功能的交互界面选择要更新的软件包。

* **--root-reqs:** 将更新限制在您的第一个学位依赖关系。

## 要求

该`require`命令将新包添加到`composer.json`当前目录中的文件中。如果没有文件存在，将立即创建一个文件。

```sh
php composer.phar require
```

添加/更改需求后，修改的需求将被安装或更新。

如果您不想以交互方式选择要求，则可以将它们传递给命令。

```sh
php composer.phar require vendor/package:2.* vendor/package2:dev-master
```

如果您未指定包裹，composer会提示您搜索包裹并给出结果，并提供要求的匹配列表。

### 选项

* **--dev:** 将包添加到`require-dev`。

* **--prefer-source:** 从`source`可用时安装软件包。

* **--prefer-dist:** 从`dist`可用时安装软件包。

* **--no-progress:** 删除可能会混淆某些不能处理退格字符的终端或脚本的进度显示。

* **--no-suggest:** 在输出中跳过建议的软件包。

* **--no-update:** 禁用依赖关系的自动更新。

* **--no-scripts:** 跳过执行中定义的脚本`composer.json`。

* **--update-no-dev:** 使用该`--no-dev`选项运行依赖关系更新。

* **--update-with-dependencies:** 还更新新需要的软件包的依赖关系，除了那些是根需求的软件包。

* **--update-with-all-dependencies:** 还更新新需要的软件包的依赖关系，包括那些根需求的软件包。

* **--ignore-platform-reqs:** 忽略`php`，`hhvm`，`lib-*`和`ext-*` 要求，并强制安装，即使在本地机器不履行这些。另请参阅[`platform`](06-config.md#platform)配置选项。

* **--prefer-stable:** 倾向于稳定版本的依赖关系。

* **--prefer-lowest:** 倾向于最低版本的依赖关系。用于测试最低版本的需求，通常用于`--prefer-stable`。
  
* **--sort-packages:** 保持包被分类`composer.json`。

* **--optimize-autoloader (-o):** 将PSR-0/4自动加载转换为classmap以获得更快的自动加载器。这是特别推荐用于生产的，但可能需要一些时间才能运行，因此目前尚未默认完成。

* **--classmap-authoritative (-a):** 仅从类映射自动加载类。隐式启用`--optimize-autoloader`。

* **--apcu-autoloader:** 使用APCu来缓存找到/未找到的类。

## 移除

使用`remove`命令从当前目录中的文件中`composer.json`移除软件包。

```sh
php composer.phar remove vendor/package vendor/package2
```

删除要求后，修改的要求将被卸载。

### 选项
* **--dev:** 从中删除软件包`require-dev`。

* **--no-progress:** 删除可能会混淆某些不能处理退格字符的终端或脚本的进度显示。

* **--no-update:** 禁用依赖关系的自动更新。

* **--no-scripts:** 跳过执行中定义的脚本`composer.json`。

* **--update-no-dev:** 使用`--no-dev`选项运行依赖关系更新。

* **--update-with-dependencies:** 还更新已删除软件包的依赖关系。

* **--ignore-platform-reqs:** 忽略`php`，`hhvm`，`lib-*`和`ext-*` 要求，并强制安装，即使在本地机器不履行这些。另请参阅[`platform`](06-config.md#platform)配置选项。

* **--optimize-autoloader (-o):** 将PSR-0/4自动加载转换为classmap以获得更快的自动加载器。这是特别推荐用于生产的，但可能需要一些时间才能运行，因此目前尚未默认完成。

* **--classmap-authoritative (-a):** 仅从类映射自动加载类。隐式启用`--optimize-autoloader`。

* **--apcu-autoloader:** 使用APCu来缓存找到/未找到的类。

## 检查平台要求

check-platform-reqs命令会检查您的PHP和扩展版本是否符合所安装软件包的平台要求。例如，这可用于验证生产服务器是否具有运行项目所需的所有扩展。

## 全局

全局命令允许你运行像其他命令`install`，`remove`，`require` 或者`update`，如果你是从运行它们[COMPOSER_HOME](#composer-home)目录。

这只是帮助管理存储在中央位置的项目，该项目可以容纳您想要随处可用的CLI工具或Composer插件。

这可用于全局安装CLI实用程序。这里是一个例子：

```sh
php composer.phar global require friendsofphp/php-cs-fixer
```

现在php-cs-fixer二进制文件在全局范围内可用 确保您的[vendor binaries](articles/vendor-binaries.md)目录位于您的$PATH 环境变量中，您可以使用以下命令获取其位置：

```sh
php composer.phar global config bin-dir --absolute
```

如果您希望稍后更新二进制文件，则可以运行全局更新：

```sh
php composer.phar global update
```

## 搜索

T搜索命令允许您搜索当前项目的软件包存储库。通常这将是packagist。您只需将它传递给您想要搜索的条款。

```sh
php composer.phar search monolog
```

您还可以通过传递多个参数来搜索多个术语。

### 选项

* **--only-name (-N):** 仅在名称中搜索。
* **--type (-t):** 搜索特定的包类型。

## 显示

要列出所有可用软件包，可以使用该`show`命令。

```sh
php composer.phar show
```

要过滤列表，您可以使用通配符传递包掩码。

```sh
php composer.phar show monolog/*

monolog/monolog 1.19.0 Sends your logs to files, sockets, inboxes, databases and 变量ious web services
```

如果您想查看某个软件包的详细信息，则可以传递软件包名称。

```sh
php composer.phar show monolog/monolog

name     : monolog/monolog
versions : master-dev, 1.0.2, 1.0.1, 1.0.0, 1.0.0-RC1
type     : library
names    : monolog/monolog
source   : [git] https://github.com/Seldaek/monolog.git 3d4e60d0cbc4b888fe5ad223d77964428b1978da
dist     : [zip] https://github.com/Seldaek/monolog/zipball/3d4e60d0cbc4b888fe5ad223d77964428b1978da 3d4e60d0cbc4b888fe5ad223d77964428b1978da
license  : MIT

autoload
psr-0
Monolog : src/

requires
php >=5.3.0
```

你甚至可以通过软件包版本，它会告诉你具体版本的细节。

```sh
php composer.phar show monolog/monolog 1.0.2
```

### 选项

* **--all :** 列出所有存储库中的所有可用软件包。

* **--installed (-i):** 列出已安装的软件包（默认情况下启用并已弃用）。

* **--platform (-p):** 仅列出平台包（php和扩展）。

* **--available (-a):** 仅列出可用软件包。

* **--self (-s):** 列出根包信息。

* **--name-only (-N):** 仅列出软件包名称。

* **--path (-P):** 列出软件包路径。

* **--tree (-t):** 将你的依赖项列为树。如果您传递包名称，它将显示该包的依赖关系树。

* **--latest (-l):** 列出所有已安装的软件包，包括其最新版本。

* **--outdated (-o):** 言下之意--latest，但这列出只是有可用更新版本的软件包。

* **--minor-only (-m):** 与--latest一起使用。仅显示具有次要SemVer兼容更新的软件包。

* **--direct (-D):** 将软件包列表限制为您的直接依赖项。

* **--strict:** 存在过期的软件包时返回非零退出代码。

* **--format (-f):** 可以选择文本（默认）或json输出格式。

## outdated

该`outdated`命令显示已安装软件包的列表，其中包含可用更新，包括其当前版本和最新版本。这基本上是一个别名 `composer show -lo`。

颜色编码是这样的：

- **绿色 (=)**: 依赖项是最新版本，并且是最新的。

- **黄色 (~)**: 依赖项有一个新版本可用，包括根据semver向后兼容性中断，所以尽可能升级，但可能涉及工作。

- **红色 (!)**: 依赖项有一个与semver兼容的新版本，你应该升级它。

### 选项

* **--all (-a):** 显示所有软件包，而不仅仅是过时的（别名`composer show -l`）。

* **--direct (-D):** 将软件包列表限制为您的直接依赖项。

* **--strict:** 如果任何软件包已过期，则返回非零退出代码。

* **--minor-only (-m):** 仅显示具有次要SemVer兼容更新的软件包。

* **--format (-f):** 可以选择文本（默认）或json输出格式。

## browse / home

该browse（别名home）在浏览器中打开一个包的存储库URL或主页。

### 选项

* **--homepage (-H):** 打开主页而不是存储库URL。
* **--show (-s):** 只显示主页或版本库URL。

## 提示

列出当前安装的软件包建议的所有软件包。您可以选择以格式`vendor/package` 输出一个或多个包名称，以将输出限制为仅由这些包提供的建议。

使用`--by-packageor` or `--by-suggestion`标志可以分别提供建议或建议的包。

使用该`--verbose (-v)`标志来显示建议包和建议原因。这意味着`--by-package --by-suggestion`，显示这两个列表。

### 选项

* **--by-package:** 通过建议包输出组。
* **--by-suggestion:** 按建议的软件包输出组。
* **--no-dev:** 不包含`require-dev`软件包建议。

## 依赖 (为什么)

该`depends`命令会告诉您哪些其他软件包依赖于某个软件包。与安装`require-dev`关系一样，仅考虑根包。

```sh
php composer.phar depends doctrine/lexer
 doctrine/annotations v1.2.7 requires doctrine/lexer (1.*)
 doctrine/common      v2.6.1 requires doctrine/lexer (1.*)
```

您可以选择在包之后指定版本约束来限制搜索。

添加`--tree`或`-t`标志以显示包依赖的原因的递归树，例如：

```sh
php composer.phar depends psr/log -t
psr/log 1.0.0 Common interface for logging libraries
|- aboutyou/app-sdk 2.6.11 (requires psr/log 1.0.*)
|  `- __root__ (requires aboutyou/app-sdk ^2.6)
|- monolog/monolog 1.17.2 (requires psr/log ~1.0)
|  `- laravel/framework v5.2.16 (requires monolog/monolog ~1.11)
|     `- __root__ (requires laravel/framework ^5.2)
`- symfony/symfony v3.0.2 (requires psr/log ~1.0)
   `- __root__ (requires symfony/symfony ^3.0)
```

### 选项

* **--recursive (-r):** 递归解析到根包。
* **--tree (-t):** 将结果打印为嵌套树，隐含-r。

## 禁止（为什么不）

该`prohibits`命令会告诉您哪些软件包阻止了安装的给定软件包。指定一个版本约束来验证是否可以在您的项目中执行升级，如果不是的话。看下面的例子：

```sh
php composer.phar prohibits symfony/symfony 3.1
 laravel/framework v5.2.16 requires symfony/变量-dumper (2.8.*|3.0.*)
```

请注意，您还可以指定平台要求，例如检查您是否可以将服务器升级到PHP 8.0：

```sh
php composer.phar prohibits php:8
 doctrine/cache        v1.6.0 requires php (~5.5|~7.0)
 doctrine/common       v2.6.1 requires php (~5.5|~7.0)
 doctrine/instantiator 1.0.5  requires php (>=5.3,<8.0-DEV)
```

就像`depends`您可以请求递归查找一样，该查找将列出取决于导致冲突的包的所有包。

### 选项

* **--recursive (-r):** 递归解析到根包。
* **--tree (-t):** 将结果打印为嵌套树，隐含-r。

## 验证

应始终运行命令 `validate`在提交`composer.json`文件之前以及在标记发布之前。它会检查你 `composer.json`的有效性。

```sh
php composer.phar validate
```

### 选项

* **--no-check-all:** 如果需要`composer.json`使用未绑定的版本约束，则不发出警告。

* **--no-check-lock:** 如果`composer.lock`存在并且不是最新的，则不发出错误。

* **--no-check-publish:** 如果`composer.json`不适合在Packagist上以包的形式发布，则不发布错误，但在其他方面有效。

* **--with-dependencies:** 还验证所有已安装依赖项的composer.json。

* **--strict:** 返回一个非零的退出代码，用于警告以及错误。

## 状态

如果您经常需要修改您的依赖项的代码，并且它们是从源代码安装的，那么该`status`命令允许您检查是否在其中任何一项中进行了本地更改。

```sh
php composer.phar status
```

通过该`--verbose`选项，您可以获得更多关于更改内容的信息：

```sh
php composer.phar status -v

You have changes in the following dependencies:
vendor/seld/jsonlint:
    M README.mdown
```

## 自我更新（selfupdate）

要将Composer自身更新为最新版本，请运行该`self-update` 命令。它会取代你`composer.phar`的最新版本。

```sh
php composer.phar self-update
```

如果您想更新到特定版本，只需指定它：

```sh
php composer.phar self-update 1.0.0-alpha7
```

如果您已为整个系统安装Composer（请参阅[全局安装](00-intro.md#globally)），则可能需要使用`root`特权运行该命令

```sh
sudo -H composer self-update
```

### 选项

* **--rollback (-r):** 回滚到您安装的最后一个版本。

* **--clean-backups:** 在更新期间删除旧备份。这使得当前版本的Composer成为更新后唯一可用的备份。

* **--no-progress:** 不输出下载进度。

* **--update-keys:** 提示用户进行密钥更新。

* **--stable:** 强制更新稳定通道。

* **--preview:** 强制更新预览频道。

* **--snapshot:** 强制更新快照频道。

## 配置

该`config`命令允许您在本地`composer.json`文件或全局`config.json`文件中编辑composer配置设置和存储库。

此外，它允许您编辑本地的大多数属性`composer.json`。

```sh
php composer.phar config --list
```

### 用法

`config [options] [setting-key] [setting-value1] ... [setting-valueN]`

`setting-key`是一个配置选项名称，`setting-value1`是一个配置值。对于可以接受数组值的设置（如 `github-protocols`），允许多个设置值参数。

您还可以编辑以下属性的值：

`description`, `homepage`, `keywords`, `license`, `minimum-stability`,
`name`, `prefer-stable`, `type` 和 `version`.

有关有效的配置选项，请参阅[Config](06-config.md)章节。

### 选项

* **--global (-g):** 在`$COMPOSER_HOME/config.json`默认的全局配置文件上运行 。如果没有此选项，此命令会影响本地`composer.json`文件或由指定的文件--file。

* **--editor (-e):** 使用由`EDITOR` 环境变量定义的文本编辑器打开本地composer.json文件。使用该`--global`选项，这将打开全局配置文件。

* **--auth (-a):** 影响auth配置文件（仅用于--editor）。.

* **--unset:** 删除名为的配置元素`setting-key`。

* **--list (-l):** 显示当前配置变量的列表。使用该`--global` 选项，仅列出全局配置。

* **--file="..." (-f):** 在特定文件而不是composer.json上运行。请注意，这不能与`--global`选项一起使用。

* **--absolute:** R在获取* -dir配置值而不是相对时返回绝对路径。

### 修改存储库

除了修改配置部分外，该`config`命令还支持通过以下方式对存储库部分进行更改：

```sh
php composer.phar config repositories.foo vcs https://github.com/foo/bar
```

如果您的存储库需要更多配置选项，则可以传递其JSON表示形式：

```sh
php composer.phar config repositories.foo '{"type": "vcs", "url": "http://svn.example.org/my-project/", "trunk-path": "master"}'
```

### 修改额外值

除了修改配置部分外，该`config`命令还支持通过以下方式对额外部分进行更改：

```sh
php composer.phar config extra.foo.bar value
```

这些圆点表示数组嵌套，但允许最大深度为3级。上述将设置 `"extra": { "foo": { "bar": "value" } }`.

## 创建项目

您可以使用Composer从现有包创建新项目。这相当于在一个`composer install` 供应商之后进行git clone/svn checkout 。

这有几个应用程序：

1. 您可以部署应用程序包。
2. 例如，您可以查看任何软件包并开始开发补丁程序。
3. 具有多个开发人员的项目可以使用此功能来引导最初的应用程序进行开发。

要使用Composer创建新项目，您可以使用该`create-project`命令。传递一个包名称和目录来创建项目。你也可以提供一个版本作为第三个参数，否则使用最新版本。

如果目录不存在，它将在安装过程中创建。

```sh
php composer.phar create-project doctrine/orm path 2.2.*
```

也可以在带有现有`composer.json`文件的目录中运行没有参数的命令来引导项目。

默认情况下，该命令检查packagist.org上的软件包。

### 选项

* **--stability (-s):** 包装的最小稳定性。默认为`stable`。

* **--prefer-source:** 从`source`可用时安装软件包。

* **--prefer-dist:** 从`dist`可用时安装软件包。

* **--repository:** 提供一个定制库来搜索这个包，而不是packagist。可以是指向`composer`存储库的HTTP URL ，本地`packages.json`文件的路径或类似于[存储库](04-schema.md#repositories) 密钥所接受的JSON字符串。

* **--dev:** 安装列出的软件包`require-dev`。

* **--no-dev:** 禁用require-dev软件包的安装。

* **--no-scripts:** 禁止执行根包中定义的脚本。

* **--no-progress:** 删除可能会混淆某些不能处理退格字符的终端或脚本的进度显示。

* **--no-secure-http:** 安装根包时临时禁用secure-http配置选项。使用风险自负。使用这个标志是一个坏主意。

* **--keep-vcs:** 跳过删除已创建项目的VCS元数据。如果以非交互模式运行该命令，这非常有用。

* **--remove-vcs:** 强制删除VCS元数据而不提示。

* **--no-install:** 禁用供应商的安装。

* **--ignore-platform-reqs:** 忽略 `php`, `hhvm`, `lib-*` and `ext-*`要求，并强制安装，即使在本地机器不履行这些。

## dump-autoload (dumpautoload)

如果您需要更新自动装载器，例如，因为classmap软件包中有新的类，则可以使用它`dump-autoload`来执行此操作，而无需进行安装或更新。

此外，由于性能原因，它可以转储优化的自动加载器，将PSR-0/4软件包转换为类映射。在有很多类的大型应用程序中，自动加载器占用每个请求时间的大部分时间。在开发中使用classmaps是不太方便的，但是使用这个选项，你仍然可以使用PSR-0/4来获得方便和类图来获得性能。

### 选项
* **--no-scripts:** 跳过`composer.json`文件中定义的所有脚本的执行。

* **--optimize (-o):** 将PSR-0/4自动加载转换为classmap以获得更快的自动加载器。这是特别推荐用于生产的，但可能需要一些时间才能运行，因此目前尚未默认完成。

* **--classmap-authoritative (-a):** 仅从类映射自动加载类。隐式启用`--optimize`。

* **--apcu:** 使用APCu来缓存找到/未找到的类。

* **--no-dev:** 禁用自动加载开发规则。

## 清除缓存（clearcache）

从Composer的缓存目录中删除所有内容。

## 许可证

列出安装的每个软件包的名称，版本和许可证。使用 `--format=json`让机器可读的输出。

### 选项

* **--format:** 输出格式：text或json（默认：“text”）
* **--no-dev:** 从输出中删除开发依赖项

## 运行脚本

### 选项

* **--timeout:** 以秒为单位设置脚本超时，或者在没有超时的情况下设置为0。
* **--dev:** 设置开发模式。
* **--no-dev:** 禁用开发模式。
* **--list (-l):** 列出用户定义的脚本。

要手动运行[脚本](articles/scripts.md)，可以使用此命令，为其提供脚本名称和可选的任何必需参数。

## EXEC

执行一个许可的二进制/脚本。您可以执行任何命令，这将确保在命令运行之前将Composer bin-dir压入PATH环境变量中。

### 选项

* **--list (-l):** 列出可用的Composer二进制文件。

## 诊断

如果你认为你发现了一个bug，或者某个东西的行为奇怪，那么你可能需要运行该`diagnose`命令来对许多常见问题执行自动检查。

```sh
php composer.phar diagnose
```

## 归档

该命令用于为给定版本的给定包生成zip/tar归档文件。它也可用于存档整个项目而不排除/忽略文件。

```sh
php composer.phar archive vendor/package 2.0.21 --format=zip
```

### 选项

* **--format (-f):** 生成的存档格式：tar或zip（默认：“tar”）
  "tar")
* **--dir:** 将存档写入此目录（默认值：“.”）
* **--file:** 用给定的文件名写入存档。

## 帮助

要获得关于某个命令的更多信息，可以使用`help`。



```sh
php composer.phar help install
```

## 命令行完成

命令行完成可以通过按照[此页面上](https://github.com/bamarni/symfony-console-autocomplete)的说明启用 。

## 环境ironment 变量iables

您可以设置许多环境变量来覆盖某些设置。只要有可能，建议在替代`config` 部分中指定这些设置`composer.json`。值得注意的是，环境变量总是优先于在中指定的值`composer.json`。

### COMPOSER

通过设置`COMPOSER`环境变量，可以将文件名设置为 `composer.json`其他值。

例如：

```sh
COMPOSER=composer-other.json php composer.phar install
```

生成的锁文件将使用相同的名称：`composer-other.lock`在本例中。

### COMPOSER_ROOT_VERSION

通过设置此变量，您可以指定根包的版本，如果它不能从VCS信息中猜出并且不存在于其中`composer.json`。

### COMPOSER_VENDOR_DIR

通过设置这个变量，你可以让Composer把依赖关系安装到一个非`vendor`。

### COMPOSER_BIN_DIR

通过设置此选项，您可以将`bin` ([Vendor Binaries](articles/vendor-binaries.md))目录更改为非`vendor/bin`。

### http_proxy or HTTP_PROXY

如果您使用HTTP代理后面的Composer，则可以使用标准 `http_proxy`或`HTTP_PROXY` 环境变量。只需将其设置为您的代理的URL。许多操作系统已经为你设置了这个变量。

使用`http_proxy`（小写）或者甚至定义两者可能会更好，因为像git或curl这样的工具只会使用小写`http_proxy`版本。或者，你也可以使用定义git代理 `git config --global http.proxy <proxy url>`。

如果您在非CLI环境中使用Composer（即集成到CMS或类似的用例），并且需要支持代理，请`CGI_HTTP_PROXY` 改为提供环境变量。有关更多详细信息，请参阅[httpoxy.org](https://httpoxy.org/)。

### no_proxy or NO_PROXY

如果您位于代理之后，并且想要为某些域禁用它，则可以使用`no_proxy`或`NO_PROXY` 环境变量。只需将其设置为代理不应用于的域的逗号分隔列表即可。

环境变量以CIDR表示法接受域，IP地址和IP地址块。你可以限制过滤器到一个特定的端口（例如:`80`）。您也可以将其设置`*`为忽略所有HTTP请求的代理。

### HTTP_PROXY_REQUEST_FULLURI

如果您使用代理但不支持request_fulluri标志，那么您应该将此环境变量设置为false或0阻止Composer设置request_fulluri选项。

### HTTPS_PROXY_REQUEST_FULLURI

如果您使用代理但不支持HTTPS请求的request_fulluri标志，那么您应该将此环境变量设置为`false`或`0`阻止Composer设置request_fulluri选项。

### COMPOSER_HOME

该`COMPOSER_HOME`可以让你改变了Composer的主目录。这是所有项目之间共享的隐藏的全局（机器上的每个用户）目录。

默认情况下，它指向`C:\Users\<user>\AppData\Roaming\Composer`在Windows和`/Users/<user>/.composer`在OSX。在遵循[XDG基本目录规范](https://specifications.freedesktop.org/basedir-spec/basedir-spec-latest.html)的 * nix 系统上，它指向`$XDG_CONFIG_HOME/composer`。在其他* nix系统上，它指向 `/home/<user>/.composer`。

#### COMPOSER_HOME/config.json

您可以将`config.json`文件放入`COMPOSER_HOME`指向的位置。`composer.json` 当您运行`install`和`update`命令时，Composer会将此配置与您的项目合并。

该文件允许您为用户的项目设置[存储库](05-repositories.md)和[配置](06-config.md)。

如果全局配置符合本地配置， 则项目中的本地配置`composer.json`始终获胜。

### COMPOSER_CACHE_DIR

该`COMPOSER_CACHE_DIR`允许您更改Composer缓存目录，这也是通过配置[`cache-dir`](06-config.md#cache-dir) 选项。

默认情况下，它指向`$COMPOSER_HOME/cache` 在*nix和OSX，以及 `C:\Users\<user>\AppData\Local\Composer`（或`%LOCALAPPDATA%/Composer`）在Windows上。



### COMPOSER_PROCESS_TIMEOUT

此环境变量控制Composer等待命令（如git命令）完成执行的时间。默认值是300秒（5分钟）。

### COMPOSER_CAFILE

通过设置此环境值，可以设置SSL/TLS对等验证期间要使用的证书包文件的路径。

### COMPOSER_AUTH

该`COMPOSER_AUTH`变种允许您设置了身份验证的环境变量。变量的内容应该是一个JSON格式的对象，包含需要的http-basic，github-oauth，bitbucket-oauth，...对象，并遵循[配置中的规范](06-config.md#gitlab-oauth)。

### COMPOSER_DISCARD_CHANGES

这个环境变量控制着[`discard-changes`](06-config.md#discard-changes)配置选项。

### COMPOSER_NO_INTERACTION

如果设置为1，则此环境变量将使Composer表现得像将`--no-interaction`标志传递 给每个命令一样。这可以在构建boxes/CI上设置。

### COMPOSER_ALLOW_SUPERUSER

如果设置为1，则此环境将以root用户身份禁用有关运行命令的警告。它也会禁用sudo会话的自动清除，所以如果你在任何时候都使用Composer作为超级用户，就像在docker容器中一样，你应该只设置它。

### COMPOSER_MEMORY_LIMIT

如果设置，该值用作php的memory_limit。

### COMPOSER_MIRROR_PATH_REPOS

如果设置为`1`，则此环境会将默认路径存储库策略更改为`mirror`而不是`symlink`。由于它是设置的默认策略，它仍然可以被存储库选项覆盖。

### COMPOSER_HTACCESS_PROTECT

默认为`1`。如果设置为`0`，Composer将不会在Composer，缓存和数据等目录中创建`.htaccess`文件。

&larr; [资源包](02-libraries.md)  |  [模式](04-schema.md) &rarr;
