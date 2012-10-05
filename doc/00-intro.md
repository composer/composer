# 说明

Composer 是一个PHP的依赖处理工具。它允许你申明项目所依赖的代码库，然后为你安装他们。

## 依赖处理

Composer 不是包管理程序.虽然它处理包或者库，但是它急于项目之上处理它们，将它们安装在你项目的路径（比如 'vendor'）下。
默认的，它不会全局地安装。因此它是一个依赖处理程序。

这种想法不是新奇的，Composer 受到 node's [npm](http://npmjs.org/) 和 ruby's [bundler](http://gembundler.com/) 的鼓舞。
但是PHP却没有类似的工具。

Composer 处理如下问题：

a) 你的项目基于多个代码库

b) 这些代码库又依赖于其他库文件

c) 你只须说明你依赖什么

d) Composer 会找出某个库的某个版本需要被安装，并且安装它们（或者说，将它们下载到你的项目中）

## 声明依赖关系

让我们假设你正在创建一个项目，并且你需要一个库帮你处理打印log信息，然后你决定
使用[monolog](https://github.com/Seldaek/monolog)。为了将它加入到你的项目中去，
你要做的仅仅是创建一个`composer.json`文件，里面对说明了项目的依赖性。

    {
        "require": {
            "monolog/monolog": "1.0.*"
        }
    }

我们简单的称述了我们项目需要 版本大于 1.0 的`monolog/monolog` 包 ，

## 安装

### 下载Composer

#### 局部性安装

为了获得Composer的帮助，我们需要完成两件事情。第一件是安装Composer (再次说一下，要做的仅仅是将它下载进你的项目中)

    $ curl -s https://getcomposer.org/installer | php

这操作仅仅确认一些PHP的设置，然后下载 `composer.phar` 到你的工作目录。这个就是Composer库。
这是个PHAR格式文件（PHP 文件），它可以帮助用户在命令行下完成一些操作。

你可以使用 `--install-dir` 选项附上目标路径（绝对路径或者相当路径均可）选择Composer的安装路径：

    $ curl -s https://getcomposer.org/installer | php -- --install-dir=bin

#### 全局性安装

你可以将上述文件置于你想要的任何位置。不过如果你选择将它放在系统 `PATH` 路径下，
你就可以全局的调用它。在类Unix系统中，你甚至可以在使用时不加 `php` 前缀.

运行一下命令，你将轻松的使得 `composer` 可以全局调用：

    $ curl -s https://getcomposer.org/installer | php
    $ sudo mv composer.phar /usr/local/bin/composer

接下来，只须输入 `composer` 就可以运行 composer了

### 使用 Composer

然后，运行 `install` 命令解决库的依赖关系：

    $ php composer.phar install

这将会下载 monolog 到 `vendor/monolog/monolog` 路径下。

## 自动加载

除了将库文件下载下来之外，Composer还为你准备了一个自动加载文件帮你加载代码库中的类。
使用它只须在你的引导文件中加入如下代码：

    require 'vendor/autoload.php';

哇唔! 现在就可以使用 monolog 了! 
请继续学习Composer的更多内容，请继续阅读 "Basic Usage" 章节。

[Basic Usage](01-basic-usage.md) &rarr;
