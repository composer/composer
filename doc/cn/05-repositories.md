# 库

本章将解释软件包和存储库的概念，可用的存储库类型以及它们的工作方式。

## 概念

在我们查看存在的不同类型的存储库之前，我们需要了解Composer构建的一些基本概念。

### 包

Composer是一个依赖管理器。它在本地安装软件包。一个包本质上是一个包含内容的目录。在这种情况下，它是PHP代码，但理论上它可以是任何东西。它包含一个包含名称和版本的包描述。名称和版本用于标识包。

实际上，Composer将每个版本视为一个独立的软件包。虽然在使用Composer时这种区别并不重要，但当您想要更改时，这一点非常重要。

除了名称和版本之外，还有一些有用的元数据。与安装最相关的信息是源定义，它描述了获取软件包内容的位置。包数据指向包的内容。这里有两个选项：dist和source。

**Dist:** dist是包数据的打包版本。通常是一个发布版本，通常是一个稳定版本。

**Source:** 该来源用于开发。这通常来自源代码库，如git。当你想修改下载的软件包时，你可以获取它。

该来源用于开发。这通常来自源代码库，如git。当你想修改下载的软件包时，你可以获取它。

### 知识库

存储库是一个包源。这是一个软件包/版本的列表。作曲家会查看您的所有存储库以查找您的项目需要的软件包。

默认情况下，只有Packagist存储库在Composer中注册。您可以通过声明将更多存储库添加到您的项目中`composer.json`。

存储库仅适用于根包，并且您的依赖关系中定义的存储库不会被加载。如果您想了解原因，请阅读 [FAQ条目](faqs/why-can't-composer-load-repositories-recursively.md)。

## 类型

### Composer

主存储库类型是`composer`存储库。它使用一个 `packages.json`包含所有包元数据的文件。

这也是packagist使用的存储库类型。要引用 `composer`存储库，请在`packages.json`文件之前提供路径。在packagist的情况下，该文件位于`/packages.json`，因此该存储库的URL将是`packagist.org`。对于`example.org/packages.json`存储库URL将是`example.org`。

#### 包

唯一必需的字段是`packages`。JSON结构如下：

```json
{
    "packages": {
        "vendor/package-name": {
            "dev-master": { @composer.json },
            "1.0.x-dev": { @composer.json },
            "0.0.1": { @composer.json },
            "1.0.0": { @composer.json }
        }
    }
}
```

该`@composer.json`标记将是`composer.json`来自该软件包版本的内容，包括最低限度：

* name
* version
* dist or source

这是一个最小的包装定义：

```json
{
    "name": "smarty/smarty",
    "version": "3.1.7",
    "dist": {
        "url": "https://www.smarty.net/files/Smarty-3.1.7.zip",
        "type": "zip"
    }
}
```

它可能包含[架构中](04-schema.md)指定的任何其他字段。

#### notify-batch

该`notify-batch`字段允许您指定每次用户安装包时都会调用的URL。该URL可以是绝对路径（将使用与存储库相同的域）或完全限定的URL。

一个示例值：

```json
{
    "notify-batch": "/downloads/"
}
```

为了`example.org/packages.json`包含一个`monolog/monolog`包，这会发送一个`POST`请求到`example.org/downloads/`下面的JSON请求主体：

```json
{
    "downloads": [
        {"name": "monolog/monolog", "version": "1.2.1.0"}
    ]
}
```

版本字段将包含版本号的标准化表示。

该字段是可选的。

#### provider-includes 和 providers-url

该`provider-includes`字段允许您列出一组列出此存储库提供的软件包名称的文件。在这种情况下，散列应该是文件的sha256。

该`providers-url`描述提供的文件是如何在服务器上找到。它是存储库根目录的绝对路径。它必须包含占位符 `%package%`和`%hash%`。

一个例子：

```json
{
    "provider-includes": {
        "providers-a.json": {
            "sha256": "f5b4bc0b354108ef08614e569c1ed01a2782e67641744864a74e788982886f4c"
        },
        "providers-b.json": {
            "sha256": "b38372163fac0573053536f5b8ef11b86f804ea8b016d239e706191203f6efac"
        }
    },
    "providers-url": "/p/%package%$%hash%.json"
}
```

这些文件包含软件包名称和哈希列表以验证文件的完整性，例如：

```json
{
    "providers": {
        "acme/foo": {
            "sha256": "38968de1305c2e17f4de33aea164515bc787c42c7e2d6e25948539a14268bb82"
        },
        "acme/bar": {
            "sha256": "4dd24c930bd6e1103251306d6336ac813b563a220d9ca14f4743c032fb047233"
        }
    }
}
```

上面的文件声明，通过加载引用的文件`providers-url`，替换 `%package%`为第三方库名称空间包名称和`%hash%`通过sha256字段，可以在此存储库中找到acme/foo和acme/bar 。这些文件本身包含[如上所述](#packages)的包定义。

这些字段是可选的。您可能不需要它们用于您自己的自定义存储库。

#### stream选项

该`packages.json`文件使用PHP流加载。您可以使用`options`参数在该流上设置额外的选项。您可以设置任何有效的PHP流上下文选项。有关更多信息，请参阅[上下文选项和参数](https://php.net/manual/en/context.php)

### VCS

VCS代表版本控制系统。这包括git，svn，fossil或hg等版本控制系统。Composer具有用于从这些系统安装软件包的存储库类型。

#### 从VCS存储库加载软件包

这有几个用例。最常见的是维护你自己的第三方库分支。如果您为项目使用某个库并决定更改库中的某些内容，那么您会希望项目使用修补版本。如果库在GitHub上（大多数情况下都是这种情况），您可以简单地将它分叉并将更改推送到您的分支。之后，你更新项目的`composer.json`。所有你需要做的就是添加你的fork作为一个仓库，并更新版本约束来指向你的自定义分支。在`composer.json`，你应该用你的自定义分支名称加前缀`"dev-"`。有关版本约束命名约定，请参阅[库](02-libraries.md)以获取更多信息。

假设您修补了monolog以修复`bugfix`分支中的错误：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/igorw/monolog"
        }
    ],
    "require": {
        "monolog/monolog": "dev-bugfix"
    }
}
```

当你运行时`php composer.phar update`，你应该`monolog/monolog`从packagist 得到你的修改版本，而不是。

请注意，除非您真的打算从长远角度出发，否则不应重新命名包装，并且完全从原始包装移开。由于自定义存储库的优先级高于packagist，因此Composer会正确选择原始包。如果要重命名包，则应该在默认分支中（通常是主分支）而不是在功能分支中执行此操作，因为软件包名称取自默认分支。

另外请注意，如果您更改`name`分叉存储库`composer.json`文件中的属性，则覆盖将不起作用，因为这需要使覆盖的原始值匹配才能正常工作。

如果其他依赖项依赖于您分叉的程序包，则可以将它内联别名，以便它匹配否则不会的约束。欲了解更多信息，[请参阅别名文章](articles/aliases.md)。

#### 使用私人存储库

完全相同的解决方案允许您在GitHub和BitBucket中使用您的私有存储库：

```json
{
    "require": {
        "vendor/my-private-repo": "dev-master"
    },
    "repositories": [
        {
            "type": "vcs",
            "url":  "git@bitbucket.org:vendor/my-private-repo.git"
        }
    ]
}
```

唯一的要求是为git客户端安装SSH密钥。

#### Git的替代品

Git不是VCS存储库支持的唯一版本控制系统。以下支持：

* **Git:** [git-scm.com](https://git-scm.com)
* **Subversion:** [subversion.apache.org](https://subversion.apache.org)
* **Mercurial:** [mercurial-scm.org](https://www.mercurial-scm.org)
* **Fossil**: [fossil-scm.org](https://www.fossil-scm.org/)

要从这些系统获取软件包，您需要安装各自的客户端。这可能是不方便的。为此，GitHub和BitBucket特别支持使用这些站点提供的API来获取软件包，而无需安装版本控制系统。VCS存储库`dist`为他们提供了将这些软件包作为zip文件获取的软件包。

* **GitHub:** [github.com](https://github.com) (Git)
* **BitBucket:** [bitbucket.org](https://bitbucket.org) (Git and Mercurial)

基于URL自动检测要使用的VCS驱动程序。但是，如果你需要指定一个不管什么原因，你可以使用 `git-bitbucket`,`hg-bitbucket`, `github`, `gitlab`, `perforce`, `fossil`, `git`, `svn` 或 `hg` 作为存储库类型，而不是 `vcs`.

如果您将`no-api`密钥设置`true`在github存储库上，它将像使用任何其他git存储库一样克隆存储库，而不是使用GitHub API。但不同于`git`直接使用驱动程序，Composer仍然会尝试使用github的zip文件。

请注意：
* **要让Composer选择使用** 存储库类型的驱动程序需要定义为“vcs”
* **如果您已经使用了私有存储库**, 这意味着Composer应该将其克隆到缓存中。如果您想要使用驱动程序安装相同的软件包，请记住启动该命令，`composer clearcache`然后使用命令`composer update`更新作曲程序缓存并从dist安装软件包。

#### BitBucket驱动程序配置

BitBucket驱动程序使用OAuth通过BitBucket REST API访问您的私人存储库，您需要创建一个OAuth使用者才能使用该驱动程序，请参阅[Atlassian的文档](https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html)。您需要填写回调网址以满足BitBucket，但地址不需要去任何地方，Composer也不会使用该地址。

在BitBucket控制面板中创建OAuth使用者后，您需要使用如下所示的凭据设置auth.json文件（更多信息，请参见[此处](https://getcomposer.org/doc/06-config.md#bitbucket-oauth)）：
```json
{
    "config": {
        "bitbucket-oauth": {
            "bitbucket.org": {
                "consumer-key": "myKey",
                "consumer-secret": "mySecret"
            }
        }
    }
}
```
**请注意，存储库端点需要是https而不是git。**

或者，如果您不希望在文件系统上拥有OAuth凭据，则可以将上述```bitbucket-oauth```块导出到[COMPOSER_AUTH](https://getcomposer.org/doc/03-cli.md#composer-auth)环境变量中。

#### Subversion选项

由于Subversion没有分支和标签的本地概念，因此默认情况下，Composer假定代码位于`$url/trunk`，`$url/branches`并且 `$url/tags`。如果您的存储库具有不同的布局，则可以更改这些值。例如，如果您使用大写的名称，则可以像这样配置存储库：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "http://svn.example.org/projectA/",
            "trunk-path": "Trunk",
            "branches-path": "Branches",
            "tags-path": "Tags"
        }
    ]
}
```

如果您没有分支或标签目录，则可以通过设置branches-path或tags-path来完全禁用它们false。

如果包是一个子目录，如`/trunk/foo/bar/composer.json`和 `/tags/1.0/foo/bar/composer.json`，那么你可以通过设置使作曲家访问其`"package-path"`选项，子目录，在这个例子中这将是`"package-path"`: `"foo/bar/"`。

如果你有一个私有的Subversion版本库，你可以在配置文件的http-basic部分保存证书（请参阅[Schema](04-schema.md)）：

```json
{
    "http-basic": {
        "svn.example.org": {
            "username": "username",
            "password": "password"
        }
    }
}
```

如果您的Subversion客户端被配置为默认存储凭证，则将为当前用户保存这些凭证，并且该服务器的现有保存凭证将被覆盖。要通过`"svn-cache-credentials"`在存储库配置中设置选项来更改此行为 ：

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "http://svn.example.org/projectA/",
            "svn-cache-credentials": false
        }
    ]
}
```

### PEAR

可以使用`pear` 存储库从任何PEAR频道安装软件包。Composer会将所有软件包名称加前缀`pear-{channelName}/` 以避免冲突。所有软件包也都带有前缀别名 `pear-{channelAlias}/`。

使用示例`pear2.php.net`：

```json
{
    "repositories": [
        {
            "type": "pear",
            "url": "https://pear2.php.net"
        }
    ],
    "require": {
        "pear-pear2.php.net/PEAR2_Text_Markdown": "*",
        "pear-pear2/PEAR2_HTTP_Request": "*"
    }
}
```

在这种情况下，频道的短名称是`pear2`，所以 `PEAR2_HTTP_Request`包名称变成`pear-pear2/PEAR2_HTTP_Request`。

> **注：** 该`pear`库需要做的每包相当多的要求，所以这可能大大减缓安装过程。

#### 自定义第三方库别名

可以使用自定义第三方库名称将PEAR通道包别名。

例：

假设您有一个私有PEAR存储库，并希望使用Composer来合并来自VCS的依赖关系。您的PEAR存储库包含以下软件包：

 * `BasePackage`
 * `IntermediatePackage`, 这取决于  `BasePackage`
 * `TopLevelPackage1` 和 `TopLevelPackage2` 这两者都依赖于`IntermediatePackage`

如果没有第三方库别名，Composer将使用PEAR通道名称作为软件包名称的第三方库部分：

 * `pear-pear.foobar.repo/BasePackage`
 * `pear-pear.foobar.repo/IntermediatePackage`
 * `pear-pear.foobar.repo/TopLevelPackage1`
 * `pear-pear.foobar.repo/TopLevelPackage2`

假设您稍后想要将您的PEAR软件包迁移到Composer存储库和命名方案，并采用第三方库名称`foobar`。使用您的PEAR软件包的项目不会看到更新的软件包，因为它们有不同的第三方库名称（`foobar/IntermediatePackage` vs `pear-pear.foobar.repo/IntermediatePackage`）。

通过`vendor-alias`从一开始就为PEAR存储库指定，您可以避免出现这种情况，并且可以避免使用您的软件包名称。

为了说明这一点，下面的例子中会得到`BasePackage`， `TopLevelPackage1`以及`TopLevelPackage2`从你的PEAR库和包`IntermediatePackage`从Github上库：

```json
{
    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/foobar/intermediate.git"
        },
        {
            "type": "pear",
            "url": "http://pear.foobar.repo",
            "vendor-alias": "foobar"
        }
    ],
    "require": {
        "foobar/TopLevelPackage1": "*",
        "foobar/TopLevelPackage2": "*"
    }
}
```

### 包

如果您想通过上述任何方式使用不支持Composer的项目，则仍然可以使用`package` 存储库自行定义该程序包。

基本上，您可以定义`composer` 存储库中包含的相同信息`packages.json`，但仅限于单个包。同样，最低要求的字段是`name`，`version`或者是`dist`或者 `source`。

以下是smarty模板引擎的示例：

```json
{
    "repositories": [
        {
            "type": "package",
            "package": {
                "name": "smarty/smarty",
                "version": "3.1.7",
                "dist": {
                    "url": "https://www.smarty.net/files/Smarty-3.1.7.zip",
                    "type": "zip"
                },
                "source": {
                    "url": "http://smarty-php.googlecode.com/svn/",
                    "type": "svn",
                    "reference": "tags/Smarty_3_1_7/distribution/"
                },
                "autoload": {
                    "classmap": ["libs/"]
                }
            }
        }
    ],
    "require": {
        "smarty/smarty": "3.1.*"
    }
}
```

通常情况下，您会将源代码部分关闭，因为您并不需要它。

> **注意：**此存储库类型有一些限制，应尽可能避免：
> - 除非您更改该`version`字段，否则Composer将不会更新软件包。
> - Composer不会更新提交引用，因此如果您使用`master`作为参考，您将不得不删除软件包以强制更新，并且必须处理不稳定的锁定文件。

存储库中的`"package"`密钥`package`可以设置为一个数组来定义一个包的多个版本：

```json
{
    "repositories": [
        {
            "type": "package",
            "package": [
                {
                    "name": "foo/bar",
                    "version": "1.0.0",
                    ...
                },
                {
                    "name": "foo/bar",
                    "version": "2.0.0",
                    ...
                }
            ]
        }
    ]
}
```

## 你自己托管

尽管您可能希望将软件包大部分时间放在pa​​ckagist上，但有一些用于托管自己的存储库的用例。

* **私人公司套餐:** 如果您是内部使用Composer进行套餐的公司的一部分，则可能需要保留这些套餐的私密性。

* **独立的生态系统：** 如果你有一个拥有自己的生态系统的项目，并且这些软件包不能被更大的PHP社区真正重用，你可能想让它们独立于包装商。这个例子就是wordpress插件。

要托管您自己的软件包，`composer`建议使用本机类型的存储库，这可提供最佳性能。

有几个工具可以帮助您创建`composer`存储库。

### 私人托管

[Private Packagist](https://packagist.com/)是一个托管或自行托管的应用程序，提供私人包裹托管以及GitHub，Packagist.org和其他包裹储存库的镜像。

查看[Private Packagist](https://packagist.com/)了解更多信息。

### Satis

Satis是一个静态`composer`存储库生成器。它有点像一个基于静态文件的超级轻量级​​版本的packagist。

您给它一个`composer.json`包含存储库，通常是VCS和包存储库定义。它将获取所有`required` 的软件包 并转储一个`packages.json`您的`composer`存储库。

检查[satis GitHub存储库](https://github.com/composer/satis)和[Satis文章](articles/handling-private-packages-with-satis.md)以获取更多信息。

### Artifact

在某些情况下，如果没有能力使上述存储库类型中的一个在线，即使是VCS也是如此。典型的例子可能是通过构建的工件交叉组织库交换。当然，大多数时候他们都是私人的。为了简化维护，您可以简单地使用`artifact`包含这些私有包的ZIP存档的文件夹的类型存储库：

```json
{
    "repositories": [
        {
            "type": "artifact",
            "url": "path/to/directory/with/zips/"
        }
    ],
    "require": {
        "private-vendor-one/core": "15.6.2",
        "private-vendor-two/connectivity": "*",
        "acme-corp/parser": "10.3.5"
    }
}
```

每个zip文件都是一个ZIP文件`composer.json`夹，位于根文件夹中：

```sh
unzip -l acme-corp-parser-10.3.5.zip

composer.json
...
```

如果有两个包含不同版本包的存档，则它们都将被导入。当更新版本的档案被添加到工件文件夹中并运行时`update`，该版本也将被导入，并且Composer将更新为最新版本。

### Path

除了工件存储库之外，您可以使用路径1，它允许您依赖本地目录，无论是绝对还是相对。这在处理整体存储库时特别有用。

例如，如果您在存储库中具有以下目录结构：
```
- apps
\_ my-app
  \_ composer.json
- packages
\_ my-package
  \_ composer.json
```

然后，要将该包`my/package`作为依赖 项添加到`apps/my-app/composer.json`文件中，可以使用以下配置：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package"
        }
    ],
    "require": {
        "my/package": "*"
    }
}
```

如果软件包是本地VCS存储库，则版本可以由当前检出的分支或标签推断。否则，版本应该在包的`composer.json`文件中明确定义。如果版本无法通过这些方式解决，则认为是`dev-master`。

如果可能的话，本地软件包将被链接，在这种情况下，控制台中的输出将被读取`Symlinked from ../../packages/my-package`。如果符号链接是没有可能的包将被复制。在这种情况下，控制台将输出M`irrored from ../../packages/my-package`。

而不是默认的回退策略，您可以强制使用符号链接 `"symlink"`: true或使用`"symlink"`: false选项进行镜像。从单一存储库部署或生成包时，强制镜像可能很有用。

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/my-package",
            "options": {
                "symlink": false
            }
        }
    ]
}
```

前导符被扩展为当前用户的主文件夹，并且环境变量在Windows和Linux / Mac符号中都被解析。例如 `~/git/mypackage`将自动加载mypackage克隆 `/home/<username>/git/mypackage`，等价于`$HOME/git/mypackage`或 `%USERPROFILE%/git/mypackage`。

> **注意：** 存储库路径也可以包含像`*`和的通配符`?`。有关详细信息，请参阅[PHP glob函数](http://php.net/glob)。

## 禁用Packagist.org

您可以通过将以下代码添加到您的`composer.json`以下来禁用默认的Packagist.org存储库 ：

```json
{
    "repositories": [
        {
            "packagist.org": false
        }
    ]
}
```

您可以使用全局配置标志全局禁用Packagist.org：

```bash
composer config -g repo.packagist false
```

&larr; [架构](04-schema.md)  |  [配置](06-config.md) &rarr;
