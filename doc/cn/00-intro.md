# 介绍

Composer是PHP中的依赖管理工具。它允许你声明你的项目依赖的库，它会为你管理（安装/更新）它们。

## 依赖管理

Composer是与Yum或Apt **不** 相同的包管理器。是的，它处理“软件包”或库，但是它在每个项目的基础上管理它们，将它们安装在项目中的目录中（例如vendor）。默认情况下，它不会全局安装任何东西。因此，它是一个依赖管理器。然而，它通过[全局](03-cli.md#global) 命令支持一个“全局”项目以方便使用 。

这个想法并不新鲜，Composer受到了node的[npm](https://www.npmjs.com/)和ruby的[bundler](https://bundler.io/)的强烈启发 。

假设：

1. 你有一个依赖于一些库的项目。
2. 其中一些图书馆依赖于其他图书馆。

Composer:

1. 使您能够声明您所依赖的库。
2. 找出哪些版本的软件包可以并需要安装，并安装它们（意思是将它们下载到您的项目中）。

有关声明依赖关系的更多详细信息，请参阅[基本用法](01-basic-usage.md)一章。

## 系统要求

Composer需要运行PHP 5.3.2+。一些敏感的php设置和编译标志也是必需的，但是当使用安装程序时，您将被警告任何不兼容性。

要从源代码安装包而不是简单的zip压缩包，您需要git，svn，fossil或hg，具体取决于包的版本控制方式。

Composer是多平台的，我们努​​力使它在Windows，Linux和OSX上同样出色运行。

## 安装 - Linux / Unix / OSX

### 下载Composer可执行文件

Composer提供了一个方便的安装程序，您可以直接从命令行执行。 如果您想了解更多关于安装程序的内部工作原理，请随时[下载此文件](https://getcomposer.org/installer) 或在[GitHub](https://github.com/composer/getcomposer.org/blob/master/web/installer)上查看它。源代码是普通的PHP。

总之，有两种安装Composer的方法。本地作为您项目的一部分，或作为全系统可执行文件在全局范围内使用。

#### 本地

要在本地安装Composer，请在项目目录中运行安装程序。请参阅 [下载页面](https://getcomposer.org/download/)以获取说明。

安装程序将检查一些PHP设置，然后下载`composer.phar` 到您的工作目录。该文件是Composer二进制文件。它是一个PHAR（PHP归档文件），它是可以在命令行上运行的PHP归档格式等等。

现在运行`php composer.phar`，以运行Composer。

您可以使用该`--install-dir` 选项将Composer安装到特定目录，并使用该选项另外（重新）命名它`--filename`。在遵循[下载页面](https://getcomposer.org/download/)的说明中运行安装程序时 ，请添加以下参数：

```
php composer-setup.php --install-dir = bin --filename = composer
```
现在运行`php bin/composer`，以运行Composer。

#### 在全局范围内

您可以将Composer PHAR放置在任何地方。如果你把它放在你的一部分的目录中`PATH`，你可以在全局访问它。在unixy系统上，您甚至可以使其可执行并在不直接使用`php` 解释器的情况下调用它。

在[下载页面](https://getcomposer.org/download/)指示信息之后运行安装程序后， 您可以运行以将composer.phar移动到路径中的目录中：

```
mv composer.phar /usr/local /bin/composer
```
如果您只想为您的用户安装它并避免需要root权限，则可以使用`~/.local/bin`默认情况下在某些Linux发行版上可用的功能。


> **注意：** 如果上述因权限而失败，则可能需要使用sudo再次运行它。

> **注意：** 在某些版本的OSX上，该`/usr`目录默认情况下不存在。如果您收到错误“/usr/local/bin/composer：没有这样的文件或目录”，那么您必须在继续之前手动创建目录： `mkdir -p /usr/local/bin`。

> **注意：** 有关更改PATH的信息，请阅读 [维基百科文章](https://en.wikipedia.org/wiki/PATH_(variable))和/或使用Google。

现在运行`composer`，以运行Composer而不是`php composer.phar`。

## 安装 - Windows

### 使用安装程序

这是让Composer在您的机器上设置的最简单方法。

下载并运行 [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe)。它将安装最新的Composer版本并设置PATH，以便您可以**composer**从命令行中的任何目录进行调用。

> **注意：** 关闭您的当前终端。使用新终端测试使用情况：这一点很重要，因为只有在终端启动时才会加载PATH。

### 手动安装

切换到您的目录`PATH`并按照[下载页面](https://getcomposer.org/download/)的说明运行安装程序 以下载`composer.phar`。

在`composer.phar`同一目录创建`composer.bat`文件：

```
C:\bin>echo @php "%~dp0composer.phar" %*>composer.bat
```

如果目录尚未存在，请将该目录添加到您的PATH环境变量中。有关更改PATH变量的信息，请参阅 [本文](https://www.computerhope.com/issues/ch000549.htm)和/或使用Google。

关闭您的当前终端。用新终端测试使用情况：

```sh
C:\Users\username>composer -V
Composer version 1.0.0 2016-01-10 20:34:53
```

## 使用Composer

现在你已经安装了Composer，你已经准备好使用它了！请转到下一章进行简短的演示。

[基本用法](01-basic-usage.md) &rarr;