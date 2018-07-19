# composer.json架构

本章将解释所有可用的字段`composer.json`。

## JSON模式

我们有一个[JSON模式](http://json-schema.org)来记录格式，也可以用来验证你的`composer.json`。实际上，它被 `validate`命令使用。你可以在https://getcomposer.org/schema.json找到它

## Root包

根包是由`composer.json`您的项目根目录定义的包。它是`composer.json`定义你的项目需求的主要部分。

某些字段仅适用于根包环境中。这方面的一个例子就是这个`config`领域。只有根包可以定义配置。依赖关系的配置被忽略。这使得该`config`领域 `root-only`。

> **注意：** 一个包可以是根包，也可以不是，取决于上下文。例如，如果您的项目依赖于`monolog`库，那么您的项目就是根包。但是，如果您`monolog`从GitHub 克隆以修复其中的错误，那么`monolog`是根包。

## 属性

### 名称

包的名称。它由第三方库名称和项目名称分隔`/`。例子：

* monolog/monolog
* igorw/event-source

该名称可以包含任何字符，包括空格，并且不区分大小写（`foo/bar`并且`Foo/Bar`被视为相同的包）。为了简化安装，建议定义一个不包含非字母数字字符或空格的小写字母名称。

发布的包（库）需要。

### 描述

对软件包的简短描述。通常这是一条线。

发布的包（库）需要。

### 版本

包的版本。在大多数情况下，这不是必需的，应该省略（见下文）。

这必须遵循的格式`X.Y.Z`或`vX.Y.Z`用一个可选的后缀`-dev`, `-patch` (`-p`), `-alpha` (`-a`), `-beta` (`-b`) 或 `-RC`.
补丁，alpha，beta和RC后缀也可以跟一个数字。

例子：

- 1.0.0
- 1.0.2
- 1.1.0
- 0.2.5
- 1.0.0-dev
- 1.0.0-alpha3
- 1.0.0-beta2
- 1.0.0-RC5
- v2.0.4-p1

如果软件包存储库可以从某个位置推断版本，如VCS存储库中的VCS标签名称，则为optional。在这种情况下，也建议省略它。

> **注意：** Packagist使用VCS存储库，所以上述声明对于Packagist也是如此。自己指定版本很可能最终会由于人为错误而在某个时刻造成问题。

### 类型

包的类型。它默认为`library`。

包类型用于自定义安装逻辑。如果你有一个需要一些特殊逻辑的包，你可以定义一个自定义类型。这可能是一个 `symfony-bundle`，一个`wordpress-plugin`或一个`typo3-cms-extension`。这些类型都将特定于某些项目，并且需要提供能够安装该类型包的安装程序。

开箱即用，Composer支持四种类型：

- **library:** 这是默认值。它将简单地将文件复制到`vendor`。
- **project:** 这表示一个项目而不是一个图书馆。例如像[Symfony标准版](https://github.com/symfony/symfony-standard)这样的应用程序外壳，像[SilverStripe安装程序](https://github.com/silverstripe/silverstripe-installer)这样的CMS 或者以软件包的形式分发的完整的应用程序。例如，IDE可以使用它来提供在创建新工作区时初始化的项目列表。
- **metapackage:** 一个包含需求的空包，将触发它们的安装，但不包含任何文件，也不会向文件系统写入任何内容。因此，它不需要安装dist或source密钥。
- **composer-plugin:** 类型包`composer-plugin`可以为其他具有自定义类型的包提供安装程序。阅读更多的 [专门文章](articles/custom-installers.md)。

如果您在安装期间需要自定义逻辑，请使用自定义类型。建议省略此字段并将其设为默认值`library`。

### 关键字

该包与之相关的一组关键字。这些可用于搜索和过滤。

例子：

- logging
- events
- database
- redis
- templating

可选的。

### 主页

项目网站的URL。

可选的。

### 自述

自述文档的相对路径。

可选的。

### time

版本的发布日期。

必须在`YYYY-MM-DD`或`YYYY-MM-DD HH:MM:SS`格式。

可选的。

### 执照

包的许可证。这可以是一个字符串或一串字符串。

最常用许可证的推荐标记是（按字母顺序）：

- Apache-2.0
- BSD-2-Clause
- BSD-3-Clause
- BSD-4-Clause
- GPL-2.0-only / GPL-2.0-or-later
- GPL-3.0-only / GPL-3.0-or-later
- LGPL-2.1-only / LGPL-2.1-or-later
- LGPL-3.0-only / LGPL-3.0-or-later
- MIT

可选，但强烈建议提供此选项。[SPDX开源许可证注册表](https://spdx.org/licenses/)中列出了更多标识符。

对于闭源软件，您可以将其`"proprietary"`用作许可证标识符。

一个例子：

```json
{
    "license": "MIT"
}
```

对于软件包，当许可证之间有选择时（“分离许可证”），可以将多个指定为数组。

分离许可示例：

```json
{
    "license": [
       "LGPL-2.1-only",
       "GPL-3.0-or-later"
    ]
}
```

或者，它们可以用 “or” 分开并用括号括起来;

```json
{
    "license": "(LGPL-2.1-only or GPL-3.0-or-later)"
}
```

同样，如果需要应用多个许可证（“联合许可证”），则应将它们用“and”分开，并用圆括号括起来。

### 作者

包的作者。这是一个对象数组。

每个作者对象可以具有以下属性：

* **name:** 作者的名字。通常他们的真实姓名。
* **email:** 作者的电子邮件地址。
* **homepage:** 作者网站的URL。
* **role:** 作者在项目中的角色（例如开发人员或翻译人员）

一个例子：

```json
{
    "authors": [
        {
            "name": "Nils Adermann",
            "email": "naderman@naderman.de",
            "homepage": "http://www.naderman.de",
            "role": "Developer"
        },
        {
            "name": "Jordi Boggiano",
            "email": "j.boggiano@seld.be",
            "homepage": "https://seld.be",
            "role": "Developer"
        }
    ]
}
```

可选，但强烈推荐。

### 支持

获取关于项目支持的各种信息。

支持信息包括以下内容：

* **email:** 电子邮件地址以获得支持
* **issues:** 问题跟踪器的网址。
* **forum:** 论坛的 URL。
* **wiki:** wiki的 URL。
* **irc:** 支持的IRC频道，如irc//server/channel。
* **source:** 浏览或下载来源的网址。
* **docs:** 文档的 URL。
* **rss:** RSS源的URL。

一个例子：

```json
{
    "support": {
        "email": "support@example.org",
        "irc": "irc://irc.freenode.org/composer"
    }
}
```

可选的。

### 包链接

以下所有对象都通过版本约束将包名称映射到包的版本。在[这里](articles/versions.md)阅读更多关于版本。

例：

```json
{
    "require": {
        "monolog/monolog": "1.0.*"
    }
}
```

所有链接都是可选字段。

`require`并且`require-dev`还支持稳定性标志([仅限根](04-schema.md#root-package))。这些允许您进一步限制或扩大包装的稳定性，超出[最小稳定性](#minimum-stability)设置的范围。例如，如果您想允许不稳定的依赖包，可以将它们应用于约束，或将它们应用于空约束。

例：

```json
{
    "require": {
        "monolog/monolog": "1.0.*@beta",
        "acme/foo": "@dev"
    }
}
```

如果你的一个依赖关系依赖于一个不稳定的包，你还需要明确地要求它以及足够的稳定性标志。

例：

假设`doctrine/doctrine-fixtures-bundle`需要`"doctrine/data-fixtures": "dev-master"` 在根composer.json中，您需要添加下面的第二行以允许`doctrine/data-fixtures`软件包的dev版本：

```json
{
    "require": {
        "doctrine/doctrine-fixtures-bundle": "dev-master",
        "doctrine/data-fixtures": "@dev"
    }
}
```

`require`并`require-dev`支持dev版本的明确引用（即提交），以确保它们被锁定到给定状态，即使在运行更新时也是如此。这些仅在您明确需要开发版本并附加引用时才有效`#<ref>`。这也是一个 [仅限根](04-schema.md#root-package)的功能，在依赖关系中将被忽略。

例：

```json
{
    "require": {
        "monolog/monolog": "dev-master#2eb0c0978d290a1c45346a1955188929cb4e5db7",
        "acme/foo": "1.0.x-dev#abc123"
    }
}
```

> **注意：** 此功能具有严格的技术限制，因为composer.json元数据仍将从散列前指定的分支名称中读取。因此，您只能在开发过程中将其作为临时解决方案来解决暂时性问题，直到您可以切换到已标记的发布。Composer团队不主动支持此功能，并且不会接受与其相关的错误报告。

也可以内联别名包约束，以便它匹配否则不会包含的约束。欲了解更多信息，请[参阅别名文章](articles/aliases.md)。

`require`并且`require-dev`还支持提及具体的PHP版本和PHP扩展你的项目需要成功运行。

例：

```json
{
    "require" : {
        "php" : "^5.5 || ^7.0",
        "ext-mbstring": "*"
    }
}
```

> **Note:** 列出项目所需的PHP扩展很重要。并非所有的PHP安装都是相同的：有些可能会遗漏你可能认为是标准的扩展（比如`ext-mysqli` Fedora/CentOS最低安装系统默认安装的扩展）。未能列出所需的PHP扩展可能会导致不良的用户体验：Composer将毫无错误地安装软件包，但在运行时会失败。该`composer show --platform`命令列出了系统中可用的所有PHP扩展。您可以使用它来帮助您编译您使用和需要的扩展列表。或者，您可以使用第三方工具来分析您的项目以获取所用扩展名列表。

#### 要求

列出此软件包所需的软件包。除非满足这些要求，否则不会安装该软件包。

#### require-dev <span>([仅限根](04-schema.md#root-package))</span>

列出开发此软件包或运行测试等所需的软件包。默认情况下安装根软件包的开发需求。两者`install`或`update`支持`--no-dev`防止安装依赖关系的选项。

#### 冲突

列出与此软件包的此版本冲突的软件包。他们将不被允许与您的包一起安装。

请注意，`<1.0 >=1.1`在`conflict`链接中指定范围时，这将与所有小于1.0 且等于或小于1.1的版本发生冲突，这可能不是您想要的。你可能想`<1.0 || >=1.1`在这种情况下去。

#### 更换

列出被这个软件包取代的软件包。这允许您分发一个包，使用自己的版本号以不同的名称发布它，而需要原始包的包继续与您的fork一起工作，因为它取代了原始包。

这对于包含子包的包也很有用，例如主要的symfony/symfony包包含所有的Symfony组件，这些组件也可作为单独的包使用。如果您需要主包装，它将自动满足单个组件之一的任何要求，因为它取代了它们。

当使用替换用于上面解释的子程序包时，建议小心。然后，您通常应该只使用`self.version`版本约束来替换，以确保主包仅替换该确切版本的子包，而不是任何其他版本，这将会不正确。

#### 提供

此软件包提供的其他软件包列表。这对于通用接口是非常有用的。一个包可以依赖于一些虚拟 `logger`包，任何实现这个记录接口的库都会简单地列出它`provide`。

#### 建议

建议的软件包可以增强或使用此软件包。这些都是信息性的，并且在安装包后显示，以便为用户提示他们可以添加更多包，尽管这些包并非严格要求。

格式与上面的包链接类似，不同之处在于值是自由文本而不是版本约束。

例：

```json
{
    "suggest": {
        "monolog/monolog": "Allows more advanced logging of the application flow",
        "ext-xml": "Needed to support XML format in class Foo"
    }
}
```

### 自动加载

自动加载映射为PHP自动加载器。

[PSR-4](http://www.php-fig.org/psr/psr-4/)和[PSR-0](http://www.php-fig.org/psr/psr-0/) 自动加载，`classmap`代和`files`包括支持。

PSR-4是推荐的方式，因为它提供了更易于使用的特性（无需在添加类时重新生成自动装载器）。

#### PSR-4

在这个`psr-4`关键字下，你定义了一个从命名空间到路径的映射，相对于软件包根。当自动载入一个像指向目录`Foo\\Bar\\Baz`的名称空间前缀`Foo\\`这样的类时，意味着自动加载器将查找名为`src/src/Bar/Baz.php`的文件并将其包含在内（如果存在）。请注意，与旧的PSR-0样式相反，prefix（`Foo\\`）`不`存在于文件路径中。

命名空间前缀必须以`\\`结尾，以避免类似前缀之间的冲突。
例如`Foo`会匹配`FooBar`命名空间中的类，因此尾随
反斜杠解决了这个问题：`Foo \\`和`FooBar \\'是不同的。

在安装/更新过程中，PSR-4引用全部组合到一个可以在生成的文件中找到的 key=>value 数组中 `vendor/composer/autoload_psr4.php`。

例：

```json
{
    "autoload": {
        "psr-4": {
            "Monolog\\": "src/",
            "Vendor\\Namespace\\": ""
        }
    }
}
```

如果您需要在多个目录中搜索相同的前缀，则可以将它们指定为一个数组，如下所示：

```json
{
    "autoload": {
        "psr-4": { "Monolog\\": ["src/", "lib/"] }
    }
}
```

如果您希望有一个后备目录，其中将查找任何命名空间，则可以使用如下的空前缀：

```json
{
    "autoload": {
        "psr-4": { "": "src/" }
    }
}
```

#### PSR-0

在这个`psr-0`关键字下，你定义了一个从命名空间到路径的映射，相对于软件包根。请注意，这也支持PEAR风格的非名称空间约定。

请注意名称空间声明应该结束`\\`以确保自动加载器完全响应。例如`Foo`匹配，`FooBar`所以后面的反斜杠可以解决问题：`Foo\\`和`FooBar\\`是不同的。

在安装/更新过程中，PSR-0引用全部组合到一个可以在生成的文件中找到的key =>value 数组中`vendor/composer/autoload_namespaces.php`。

例：

```json
{
    "autoload": {
        "psr-0": {
            "Monolog\\": "src/",
            "Vendor\\Namespace\\": "src/",
            "Vendor_Namespace_": "src/"
        }
    }
}
```

如果您需要在多个目录中搜索相同的前缀，则可以将它们指定为一个数组，如下所示：

```json
{
    "autoload": {
        "psr-0": { "Monolog\\": ["src/", "lib/"] }
    }
}
```

PSR-0风格不仅限于名称空间声明，而是可以直接指定到类级别。这对全局名称空间中只有一个类的库很有用。例如，如果php源文件也位于包的根目录中，则可以这样声明它：

```json
{
    "autoload": {
        "psr-0": { "UniqueGlobalClass": "" }
    }
}
```

如果你想有一个可以使用任何命名空间的回退目录，你可以使用一个空的前缀，如：

```json
{
    "autoload": {
        "psr-0": { "": "src/" }
    }
}
```

#### 类映射

这些`classmap`引用在安装/更新期间全部组合为一个可以在生成的文件中找到的 key=>value 数组 `vendor/composer/autoload_classmap.php`。这张地图是通过扫描所有目录/文件中的文件`.php`以及`.inc`文件中的类来构建的。

您可以使用类映射生成支持为所有不遵循PSR-0/4的库定义自动加载。要配置它，可以指定所有目录或文件来搜索类。

例：

```json
{
    "autoload": {
        "classmap": ["src/", "lib/", "Something.php"]
    }
}
```

#### 文件

如果你想在每个请求上明确地要求某些文件，那么你可以使用`files`自动加载机制。如果您的软件包包含PHP无法自动加载的PHP函数，这非常有用。

例：

```json
{
    "autoload": {
        "files": ["src/MyLibrary/functions.php"]
    }
}
```

#### 排除classmaps中的文件

如果你想从类图中排除一些文件或文件夹，你可以使用该`exclude-from-classmap`属性。这可能会有助于排除实时环境中的测试类，例如，即使在构建优化的自动加载器时，它们也会从类映射中跳过。

类映射生成器将忽略此处配置的路径中的所有文件。路径绝对来自包根目录（即composer.json位置），并支持`*`匹配除斜杠之外的任何内容，并`**`匹配任何内容。`**`被隐式添加到路径的末尾。

例：

```json
{
    "autoload": {
        "exclude-from-classmap": ["/Tests/", "/test/", "/tests/"]
    }
}
```

#### 优化自动装载机

自动加载器可以对您的请求时间产生相当大的影响（在使用大量类的大型框架中，每个请求需要50-100毫秒）。有关[优化自动装载机的文章](articles/autoloader-optimization.md)，请参阅有关如何减少此影响的更多详细信息。

### autoload-dev <span>([仅限根](04-schema.md#root-package))</span>

本部分允许为开发目的定义自动加载规则。

运行测试套件所需的类不应包含在主自动加载规则中，以避免污染生产中的自动加载器，以及其他人何时使用您的软件包作为依赖项。

因此，依靠单元测试的专用路径并将其添加到autoload-dev部分中是一个好主意。

例：

```json
{
    "autoload": {
        "psr-4": { "MyLibrary\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "MyLibrary\\Tests\\": "tests/" }
    }
}
```

### 包括路径

> **弃用**: 这只是为了支持传统项目而存在，所有新代码应该最好使用自动加载。因此，这是一个不推荐的做法，但该功能本身不可能从Composer中消失。

应该附加到PHP的路径列表include_path。

例：

```json
{
    "include-path": ["lib/"]
}
```

可选的。

### 目标目录

> **弃用**: 这只是为了支持传统的PSR-0样式自动加载，并且所有新代码应该最好使用没有目标代码的PSR-4，并且使用PSR-0的项目与PHP命名空间相反地被鼓励迁移到PSR-4。

定义安装目标。

如果包根位于命名空间声明之下，则无法正确自动加载。`target-dir`解决了这个问题。

Symfony就是一个例子。这些组件有单独的包。Yaml组件在下`Symfony\Component\Yaml`。包根是该`Yaml`目录。为了使自动加载成为可能，我们需要确保它没有安装到`vendor/symfony/yaml`，但是代之以进入 `vendor/symfony/yaml/Symfony/Component/Yaml`，以便自动加载器可以加载它`vendor/symfony/yaml`。

要做到这一点，`autoload`并`target-dir`定义如下：

```json
{
    "autoload": {
        "psr-0": { "Symfony\\Component\\Yaml\\": "" }
    },
    "target-dir": "Symfony/Component/Yaml"
}
```

可选的。

### 最小稳定性 <span>([仅限根](04-schema.md#root-package))</span>

这定义了稳定性过滤包的默认行为。这默认为`stable`，所以如果你依赖一个`dev`包，你应该在你的文件中指定它以避免意外。

检查每个软件包的所有版本的稳定性，而那些不如`minimum-stability`设置稳定的软件包在解决项目依赖性时会被忽略。（请注意，您也可以在每个包中使用在`require`块中指定的版本约束中的稳定性标志来指定稳定性要求（有关更多详细信息，请参阅[包链接](#package-links)）。

可用的选项（在稳定的顺序） `dev`, `alpha`, `beta`, `RC`,
和 `stable`.

### 偏好稳定 <span>([仅限根](04-schema.md#root-package))</span>

启用此功能后，如果找到兼容的稳定软件包，Composer会选择比稳定软件包更稳定的软件包。如果你需要一个开发版本或者只有一个软件包可以使用alpha，那么这些仍然会被选中，以保证最小稳定性。

使用`"prefer-stable": true`启用。

### 存储库 <span>([仅限根](04-schema.md#root-package))</span>

使用自定义软件包存储库。

默认情况下，Composer仅使用packagist存储库。通过指定存储库，您可以从别处获取软件包。

存储库不会递归解析。你只能将它们添加到你的主 `composer.json`。依赖关系的存储库声明`composer.json`被忽略。

以下储存库类型受支持：

* **composer:** Composer仓库只是`packages.json`通过网络（HTTP，FTP，SSH）提供的文件，其中包含`composer.json` 具有附加信息`dist`和/或`source`信息的对象列表。该`packages.json`文件使用PHP流加载。您可以使用`options`参数在该流上设置额外的选项。

* **vcs:** 版本控制系统存储库可以从git，svn，fossil和hg存储库中获取软件包。

* **pear:** 有了这个，你可以将任何梨库导入到你的Composer项目中。

* **package:** 如果你依赖的是一个没有任何支持composer的项目，你可以使用一个`package` 仓库来内联定义这个包。你基本上内联`composer.json`对象。

有关这些信息的更多信息，请参阅[存储库](05-repositories.md)。

例：

```json
{
    "repositories": [
        {
            "type": "composer",
            "url": "http://packages.example.com"
        },
        {
            "type": "composer",
            "url": "https://packages.example.com",
            "options": {
                "ssl": {
                    "verify_peer": "true"
                }
            }
        },
        {
            "type": "vcs",
            "url": "https://github.com/Seldaek/monolog"
        },
        {
            "type": "pear",
            "url": "https://pear2.php.net"
        },
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
                    "url": "https://smarty-php.googlecode.com/svn/",
                    "type": "svn",
                    "reference": "tags/Smarty_3_1_7/distribution/"
                }
            }
        }
    ]
}
```

> **注意：** 这里的订单很重要。在寻找一个包时，Composer会从第一个库到最后一个库，然后选择第一个匹配。默认情况下Packagist是最后添加的，这意味着自定义存储库可以覆盖它。

使用JSON对象表示法也是可能的。但是，JSON键/值对将被视为无序，因此无法保证一致的行为。

 ```json
{
    "repositories": {
         "foo": {
             "type": "composer",
             "url": "http://packages.foo.com"
         }
    }
}
 ```

### 配置 <span>([仅限根](04-schema.md#root-package))</span>

一组配置选项。它只用于项目。有关各个选项的说明，请参阅 [配置](06-config.md)。

### 脚本 <span>([仅限根](04-schema.md#root-package))</span>

Composer允许您通过使用脚本来挂接安装过程的各个部分。

有关活动详情和示例，请参阅[脚本](articles/scripts.md)。

### 额外

任意额外的数据供消费者使用`scripts`。

这可以是几乎任何事情。要从脚本事件处理程序中访问它，您可以执行以下操作：

```php
$extra = $event->getComposer()->getPackage()->getExtra();
```

可选的。

### bin

一组应该被视为二进制文件并被链接到`bin-dir` （从配置文件中）的文件。

请参阅[第三方库二进制文件](articles/vendor-binaries.md)了解更多详细信

可选的。

### 归档

一组用于创建程序包归档的选项。

支持以下选项：

* **排除:** 允许配置排除路径的模式列表。模式语法与.gitignore文件匹配。即使先前的模式排除了它们，主导感叹号（！）也会导致包含任何匹配的文件。前导斜杠只会在项目相对路径的开始处匹配。星号不会展开到目录分隔符。

例：

```json
{
    "archive": {
        "exclude": ["/foo/bar", "baz", "/*.test", "!/foo/bar/baz"]
    }
}
```

例子包括 `/dir/foo/bar/file`, `/foo/bar/baz`, `/file.php`,
`/foo/my.test` 但将排除 `/foo/bar/any`, `/foo/baz`, 和 `/my.test`.

可选的。

### 弃用

指示此包是否已被放弃。

它可以是布尔值，也可以是指向推荐替代品的包名/ URL。

例子：

使用`"abandoned": true`来表示这个包被放弃。使用`"abandoned": "monolog/monolog"`来表示这个包被放弃，推荐的替代方案是 `monolog/monolog`。

默认为false。

可选的。

### 非特征分支

非数字（例如“最新”或其他）的分支名称的正则表达式列表，不会作为特征分支处理。这是一串字符串。

如果您有非数字分支名称，例如“最新”，“当前”，“最新稳定”或类似于版本号的东西，则Composer将处理诸如功能分支之类的分支。这意味着它搜索父分支，它看起来像一个版本，或者结束于特殊分支（如主分支），并且根包版本号成为父分支的版本或者至少是主或者其他东西。

要将非数字命名分支作为版本处理，而不是使用有效版本或特殊分支名称（例如master）搜索父分支，可以为分支名称设置模式，这应该作为开发版本分支来处理。

当你使用“self.version”进行依赖时，这非常有用，因此不是dev-master，而是安装了相同的分支（在这个例子中：latest-testing）。

一个例子：

如果您有一个测试分支，在测试阶段大量维护并且部署到分段环境中，通常`composer show -s`会给您v`ersions : * dev-master`。

如果您`latest-.*`将此配置为非功能分支的模式：

```json
{
    "non-feature-branches": ["latest-.*"]
}
```

然后`composer show -s`会给你`versions : * dev-latest-testing`。

可选的。

&larr; [命令行界面](03-cli.md)  |  [存储库](05-repositories.md) &rarr;
