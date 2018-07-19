# 基本用法

## 介绍

对于我们的基本使用介绍，我们将安装`monolog/monolog`一个日志库。如果您尚未安装Composer，请参阅 [Intro](00-intro.md) 章节。

> **注意：** 为简单起见，此介绍将假定您已执行Composer 的[本地](00-intro.md#locally)安装。

## `composer.json`：项目设置

要开始在项目中使用Composer，您只需要一个`composer.json` 文件。这个文件描述了你的项目的依赖关系，也可能包含其他元数据。

### 该 `require` 关键

你指定的第一件（也是唯一的）`composer.json`是 [require](04-schema.md#require)关键。你只是告诉Composer你的项目所依赖的包装。

```
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

正如你所看到的，[require](04-schema.md#require)需要一个将 **包名** （例如`monolog/monolog`）映射 到 **版本约束**（例如 `1.0.*`）的对象。

Composer使用此信息来搜索使用[repositories](04-schema.md#repositories) 密钥注册的软件包“存储库”中的正确文件集合，或者在Packagist（默认软件包存储库）中搜索正确的文件集合。在上面的例子中，由于文件中没有注册其他仓库`composer.json`，因此假定该`monolog/monolog`软件包已在Packagist上注册。（查看更多有关Packagist[在以下](#packagist)，或阅读更多有关存储库[在这里](05-repositories.md)）。

### 程序包名称

软件包名称由第三方库名称和项目名称组成。通常这些将是相同的 - 第三方库名称仅用于防止命名冲突。例如，它将允许两个不同的人创建一个名为的库`json`。一个可能会被命名，`igorw/json`而另一个可能会被命名`seldaek/json`。

阅读更多关于发布软件包和软件包命名的[信息](02-libraries.md)。（请注意，您也可以指定“平台包”作为依赖关系，从而允许您要求特定版本的服务器软件。请参阅 下面的[平台包](#platform-packages)。）

### 包版本约束

在我们的例子中，我们要求版本约束的Monolog包 [1.0.*](https://semver.mwl.be/#?package=monolog%2Fmonolog&version=1.0.*)。这意味着`1.0`开发分支中的任何版本，或任何大于或等于1.0且小于1.1（`>=1.0 <1.1`）的版本。

请阅读[版本](articles/versions.md)以获取更多版本信息，版本之间的相互关系以及版本限制。

> **Composer如何下载正确的文件？** 当您在中指定依赖项时 `composer.json`，Composer将首先获取您请求的包的名称，然后在您使用该[repositories](04-schema.md#repositories)密钥注册的任何存储库中进行搜索 。如果您尚未注册任何额外的存储库，或者它没有在您指定的存储库中找到具有该名称的包，则会退回到Packagist（更多，见[下文](#packagist)）。

> 当Composer找到正确的软件包时，无论是在Packagist中还是在您指定的回购软件中，它都会使用软件包VCS的版本控制功能（即分支和标签）来尝试为您指定的版本约束找到最佳匹配。请务必阅读[版本文章](articles/versions.md)中的版本和软件包解析。

> **注意：** 如果您试图要求包装，但Composer在包装稳定性方面会引发错误，则您指定的版本可能不符合默认的最低稳定性要求。默认情况下，在VCS中搜索有效的软件包版本时只考虑稳定版本。

> 如果您尝试要求软件包的开发版，Alpha版，Beta版或RC版，您可能会遇到此问题。详细了解稳定性标志和[架构页面](04-schema.md)`minimum-stability` 上的键。

## 安装依赖关系

要为您的项目安装已定义的依赖关系，请运行该 [`install`](03-cli.md#install)命令。

```sh
php composer.phar install
```

当你运行这个命令时，可能会发生以下两种情况之一：

### 安装时没有composer.lock

如果您以前从未运行过此命令，并且也没有任何`composer.lock`文件存在，Composer只会解析`composer.json`文件中列出的所有依赖项，并将其最新版本的文件下载到`vendor`项目中的目录中。（该`vendor` 目录是项目中所有第三方代码的常规位置）。在我们上面的例子中，你最终会得到Monolog源文件 `vendor/monolog/monolog/`。如果Monolog列出了任何依赖关系，那么这些依赖关系也将在文件夹下`vendor/`。

> **提示：** 如果您使用的git为您的项目，你可能要添加 `vendor`在你的`.gitignore`。您真的不想将所有第三方代码添加到版本化的存储库中。

当Composer完成安装后，它会写入所有软件包及其下载到`composer.lock`文件的确切版本，并将项目锁定到这些特定版本。你应该把这个`composer.lock`文件提交到你的项目仓库中，这样所有在这个项目上工作的人都被锁定到相同版本的依赖项上（下面更多）。

### 安装时有composer.lock

这带来了第二种情况。如果在运行时已经存在`composer.lock`文件和 `composer.json`文件`composer install`，则表示您install之前运行过 命令，或者项目中的其他人运行了`install`命令并将该`composer.lock`文件提交给项目（这很好）。

无论哪种方式，`install`当存在`composer.lock`文件时运行解析并安装列出的所有依赖项`composer.json`，但`Composer`使用列出的确切版本`composer.lock`来确保程序包版本与工作中的每个人都一致。因此，您将拥有`composer.json文`件所请求的所有依赖关系 ，但它们可能并不都是最新的可用版本（`composer.lock`自文件创建以来，文件中列出的某些依赖关系可能已经发布了更新的版本）。这是设计，它确保您的项目不会因为依赖关系中的意外更改而中断。

### 将您的`composer.lock`文件提交到版本控制

将此文件提交给VC非常重要，因为它会使设置项目的任何人使用您正在使用的完全相同版本的依赖项。您的CI服务器，生产计算机，团队中的其他开发人员，所有人和每个人都运行在相同的依赖关系上，这可以减少只影响部分部署的错误的可能性。即使您独自开发，在重新安装项目的六个月内，即使您的依赖关系从那时起发布了许多新版本，您仍然可以确信已安装的依赖项仍然有效。（请参阅下面关于使用该`update`命令的注意事项。）

## 将依赖关系更新到最新版本

如上所述，该`composer.lock`文件阻止您自动获取最新版本的依赖关系。要更新到最新版本，请使用该[`update`](03-cli.md#update)命令。这将获取最新的匹配版本（根据您的`composer.json`文件）并使用新版本更新锁定文件。（这相当于删除`composer.lock`文件并`install`再次运行。）

```
php composer.phar update
```
> **注意：** 执行`install`时，如果`composer.lock`和`composer.json`未同步，`Composer`将在执行命令时显示警告。

如果您只想安装或更新一个依赖项，可以将它们列入白名单：

```
php composer.phar update monolog/monolog [...]
```

> **注意：** 对于库，不需要提交锁定文件，另请参阅：[库 - 锁定文件](02-libraries.md#lock-file)。

## Packagist

[Packagist](https://packagist.org/)是主要的Composer存储库。Composer存储库基本上是一个软件包源代码：您可以从中获取软件包的位置。Packagist的目标是成为每个人都使用的中央存储库。这意味着您可以自动`require`在其中提供任何软件包，而无需进一步指定Composer应在何处查找软件包。

如果你去[Packagist](https://packagist.org/)网站（packagist.org），你可以浏览和搜索软件包。

任何使用Composer的开源项目都推荐在Packagist上发布他们的软件包。一个库不需要在Composagist上使用，但它可以使其他开发人员更快地发现和采用。

## 平台包

Composer具有平台软件包，这些软件包是安装在系统上但不能由Composer实际安装的虚拟软件包。这包括PHP本身，PHP扩展和一些系统库。

* `php`代表用户的PHP版本，允许您应用约束，例如`>=5.4.0`。要求64位版本的php，你可以要求`php-64bit`包装。

* `hhvm`表示HHVM运行时的版本并允许您应用约束，例如`>=2.3.3`。

* `ext-<name>`允许您要求PHP扩展（包括核心扩展）。版本控制在这里可能非常不一致，因此将约束设置为是个好主意`*`。一个扩展包名称的例子是`ext-gd`。

* `lib-<name>`允许在PHP使用的库的版本上进行约束。以下是可供选择：`curl`, `iconv`, `icu`, `libxml`,  `openssl`, `pcre`, `uuid`, `xsl`。

您可以使用[show --platform](03-cli.md#show)获取本地可用平台包的列表。

## 自动加载

对于指定自动加载信息的库，Composer会生成一个` vendor/autoload.php`文件。您可以简单地包含此文件，并开始使用这些库提供的类，而无需额外的工作：

```
require __DIR__ . '/vendor/autoload.php';

$log = new Monolog\Logger('name');
$log->pushHandler(new Monolog\Handler\StreamHandler('app.log', Monolog\Logger::WARNING));
$log->addWarning('Foo');
```

您甚至可以通过添加[`autoload`](04-schema.md#autoload) 字段将自己的代码添加到自动装载器 `composer.json`。

```
{
    "autoload": {
        "psr-4": {"Acme\\": "src/"}
    }
}
```

Composer将为该命名空间注册一个[PSR-4](http://www.php-fig.org/psr/psr-4/) 自动加载器`Acme`。

您可以定义从命名空间到目录的映射。该`src`目录将位于您的项目根目录中，与`vendor`目录位于同一级别。示例文件名将`src/Foo.php`包含一个`Acme\Foo`类。

添加[`autoload`](04-schema.md#autoload)字段后，您必须重新运行 [`dump-autoload`](03-cli.md#dump-autoload)以重新生成 `vendor/autoload.php`文件。

包含该文件还将返回自动加载器实例，因此您可以将包含调用的返回值存储在变量中并添加更多名称空间。例如，这对于自动加载测试套件中的类很有用。

```
$loader = require __DIR__ . '/vendor/autoload.php';
$loader->addPsr4('Acme\\Test\\', __DIR__);
```

除PSR-4自动加载外，Composer还支持PSR-0，类图和文件自动加载。有关[`autoload`](04-schema.md#autoload)更多信息，请参阅参考。

另请参阅关于[优化自动装载器](articles/autoloader-optimization.md)的文档。

> **注意：** Composer提供自己的自动加载器。如果你不想使用那个，你可以包含`vendor/composer/autoload_*.php`文件，它返回关联数组，允许你配置自己的自动加载器。

&larr; [Intro](00-intro.md)  |  [库](02-libraries.md) &rarr;
