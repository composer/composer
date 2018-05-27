# 资源包

本章将告诉您如何使您的资源包通过Composer安装

## 每个项目都是一个包

只要`composer.json`在目录中有一个目录，该目录就是一个包。当你添加一个[`require`](04-schema.md#require)项目时，你正在制作一个依赖于其他软件包的软件包。你的项目和一个资源包唯一的区别是你的项目是一个没有名字的包。

为了使这个软件包可以安装，你需要给它一个名字。您可以通过添加[`name`](04-schema.md#name)属性来执行此操作`composer.json`：

```json
{
    "name": "acme/hello-world",
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

在这种情况下，项目名称是`acme/hello-world`，`acme`供应商名称在哪里。提供供应商名称是强制性的。

>**注意：** 如果您不知道如何使用供应商名称，那么您的GitHub用户名通常是一个不错的选择。虽然软件包名称不区分大小写，但惯例全部为小写字母并且为了分词而使用破折号。

## 库版本控制

在绝大多数情况下，您将使用某种类型的版本控制系统（如git，svn，hg或化石）来维护您的库。在这些情况下，Composer推断VCS中的版本，并且 **不应** 在`composer.json`文件中指定版本。（请参阅[版本文章](articles/versions.md) 以了解Composer如何使用VCS分支和标签来解决版本限制。）

如果您手动维护软件包（即没有VCS），则需要通过`version`在`composer.json` 文件中添加值来明确指定版本：

```json
{
    "version": "1.0.0"
}
```

>**注意：** 将硬编码版本添加到VCS时，版本将与标签名称冲突。Composer将无法确定版本号。

### VCS版本控制

Composer使用您的VCS的分支和标签功能将您在`require`字段中指定的版本约束条件解析为特定的文件集。在确定有效的可用版本时，Composer将查看所有标签和分支，并将其名称转换为内部选项列表，然后与您提供的版本限制进行匹配。

有关Composer如何处理标签和分支以及如何解决软件包版本限制的更多信息，请阅读[版本文章](articles/versions.md)。

## 锁定文件

对于你的资源包，`composer.lock`如果你愿意，你可以提交这个文件。这可以帮助您的团队始终对相同的依赖版本进行测试。但是，这个锁定文件不会对依赖它的其他项目产生任何影响。它只对主项目有影响。

如果你不想提交锁文件，并且你正在使用git，那么将它添加到`.gitignore`。

## 发布到VCS

一旦你有一个包含`composer.json`文件的VCS存储库（版本控制系统，例如git） ，你的库已经可以作为composer安装。在这个例子中，我们将`acme/hello-world`在GitHub下发布这个库 `github.com/username/hello-world`。

现在，为了测试安装`acme/hello-world`包，我们在本地创建一个新项目。我们会取名它`acme/blog`。这个博客将取决于 `acme/hello-world`，而这又取决于`monolog/monolog`。我们可以通过在blog某处创建一个新目录来实现这一目标，其中包含 `composer.json`：

```json
{
    "name": "acme/blog",
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

在这种情况下名称是不需要的，因为我们不想将博客作为库发布。它在这里加入以澄清`composer.json`正在描述的内容。

现在我们需要告诉博客应用程序在哪里找到hello-world依赖关系。我们通过向博客添加软件包存储库规范来做到这一点 `composer.json`：

```json
{
    "name": "acme/blog",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/username/hello-world"
        }
    ],
    "require": {
        "acme/hello-world": "dev-master"
    }
}
```

有关程序包存储库如何工作以及可用的其他类型的更多详细信息，请参阅[存储库](05-repositories.md)。

就这样。您现在可以通过运行Composer的[install](03-cli.md#install)命令来安装依赖项 ！

**总之：** 任何 git/svn/hg/fossil 存储库包含一个`composer.json`都可以通过指定软件包存储库并在该[require](04-schema.md#require)字段中声明依赖关系来添加到您的项目中。

## 发布到packagist

好吧，现在你可以发布软件包了。但是每次指定VCS存储库都很麻烦。您不想强制所有用户这样做。

您可能已经注意到的另一件事是，我们没有指定一个软件包存储库`monolog/monolog`。这是如何工作的？答案是Packagist。

[Packagist](https://packagist.org/)是Composer的主要软件包存储库，并且默认情况下已启用。任何发布在Packagist上的内容都可以通过Composer自动获得。由于 [Monolog在Packagist](https://packagist.org/packages/monolog/monolog)上，我们可以依靠它而不必指定任何额外的存储库。

如果我们想`hello-world`与世界分享，我们也会在Packagist上发布它。这样做非常简单。

您只需访问[Packagist](https://packagist.org/)并点击“提交”按钮。如果您尚未注册，则会提示您注册，然后允许您将URL提交到VCS存储库，此时Packagist将开始对其进行爬网。一旦完成，你的软件包将供任何人使用！

&larr; [基本用法](01-basic-usage.md) |  [命令行界面](03-cli.md) &rarr;
