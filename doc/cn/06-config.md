# 配置

本章将介绍`config`一节的`composer.json` [模式](04-schema.md)。

## process-timeout

默认为`300`。像git克隆这样的持续时间过程可以在Composer假定它们消失之前运行。如果连接速度较慢或第三方库数量较多，则可能需要提高此值。

## use-include-path

默认为`false`。如果`true`，Composer自动加载器也将在PHP包含路径中查找类。

## preferred-install

默认为`auto`可以是任何的`source`，`dist`或`auto`。此选项允许您设置Composer喜欢使用的安装方法。可以选择性地使用模式的哈希以获得更详细的安装偏好。

```json
{
    "config": {
        "preferred-install": {
            "my-organization/stable-package": "dist",
            "my-organization/*": "source",
            "partner-organization/*": "auto",
            "*": "dist"
        }
    }
}
```

> **注意：** 订单很重要。更具体的模式应该早于更宽松的模式。将字符串符号与全局和程序包配置中的哈希配置混合时，字符串符号将转换为`*`程序包模式。

## store-auths

在提示进行身份验证后要做什么，其中一个:`true`(始终存储）， `false`（不存储）和`"prompt"`（每次询问），默认为`"prompt"`。

## github-protocols

默认为`["https", "ssh", "git"]`。按照优先顺序从github.com克隆时使用的协议列表。默认情况下`git`是存在的，但仅当[secure-http](#secure-http) 被禁用时，因为git协议未加密。如果您希望您的原始远程推送网址使用https而不是ssh（`git@github.com:...`），那么请将协议列表设置为唯一`["https"]`，Composer将停止将推送网址覆盖为ssh网址。

## github-oauth

域名和oauth密钥的列表。例如，使用`{"github.com": "oauthtoken"}`此选项的值将用于`oauthtoken`访问github上的私有存储库，并规避基于IP的API速率限制。[详细](articles/troubleshooting.md#api-rate-limit-and-oauth-tokens)了解如何获取GitHub的OAuth令牌。

## gitlab-oauth

域名和oauth密钥的列表。例如，使用`{"gitlab.com": "oauthtoken"}`此选项的值将用于`oauthtoken`访问gitlab上的私有存储库。请注意：如果该软件包不在 gitlab.com 上，则必须使用该[`gitlab-domains`](06-config.md#gitlab-domains)选项指定域名 。

## gitlab-token

域名和私有令牌的列表。例如，使用`{"gitlab.com": "privatetoken"}`此选项的值将用于`privatetoken`访问gitlab上的私有存储库。请注意：如果该软件包不在 gitlab.com 上，则必须使用该[`gitlab-domains`](06-config.md#gitlab-domains)选项指定域名 。

## disable-tls

默认为`false`。如果设置为true，则将使用HTTP尝试所有HTTPS URL，而不执行网络级加密。启用此功能存在安全风险，不建议使用。更好的方法是在php.ini中启用php_openssl扩展。

## secure-http

默认为`true`。如果设置为true，则只允许通过Composer下载HTTPS URL。如果你确实需要HTTP访问功能，那么你可以禁用它，但使用[Let's Encrypt](https://letsencrypt.org/)来获得免费的SSL证书通常是更好的选择。

## bitbucket-oauth

一个域名和消费者名单。例如使用`{"bitbucket.org": {"consumer-key": "myKey", "consumer-secret": "mySecret"}}`。[阅读](https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html) 如何在Bitbucket上设置使用者。

## cafile

证书颁发机构文件在本地文件系统上的位置。在PHP 5.6或更高版本中，您应该通过php.ini中的openssl.cafile来设置它，尽管PHP 5.6+应该能够自动检测您的系统CA文件。

## capath

如果未指定cafile或未在其中找到证书，则会搜索capath指向的目录以查找合适的证书。capath必须是正确散列的证书目录。

## http-basic

域名和用户名/密码列表，以对其进行身份验证。例如，使用`{"example.org": {"username": "alice", "password": "foo"}}`此选项的值将使Composer对example.org进行身份验证。

> **注意：** 身份验证相关的配置选项类似`http-basic`， `github-oauth`也可以在`auth.json`除了您的文件之外的文件中指定`composer.json`。这样你可以对它进行gitignore，每个开发者都可以在那里放置自己的凭证。

## platform

让您伪造平台包（PHP和扩展），以便您可以模拟生产环境或在配置中定义目标平台。例如：`{"php": "7.0.3", "ext-something": "4.0.3"}`。

## vendor-dir

默认为`vendor`。如果需要，可以将依赖关系安装到不同的目录中。`$HOME`并且`~`将被vendor-dir中的主目录路径和`*-dir`下面的所有选项所取代。

## bin-dir

默认为`vendor/bin`。如果一个项目包含二进制文件，它们将被链接到这个目录中。

## data-dir

默认为`C:\Users\<user>\AppData\Roaming\Composer`在Windows上， `$XDG_DATA_HOME/composer`遵循XDG基本目录规范的`$home`在unix系统上以及其他unix系统上。现在它只用于存储过去的composer.phar文件，以便能够回滚到旧版本。另请参阅[COMPOSER_HOME](03-cli.md#composer-home)。

## cache-dir

默认为`C:\Users\<user>\AppData\Local\Composer`在Windows上， `$XDG_CACHE_HOME/composer`在遵循XDG基本目录规范的`$home/cache`在unix系统上以及其他unix系统上。存储Composer使用的所有缓存。另请参阅[COMPOSER_HOME](03-cli.md#composer-home)。


## cache-files-dir

默认为`$cache-dir/files`。存储软件包的zip存档。

## cache-repo-dir

默认为`$cache-dir/repo`。存储库的元数据`composer` 类型和类型的VCS回购 `svn`, `fossil`, `github` 和 `bitbucket`.

## cache-vcs-dir

默认为`$cache-dir/vcs`。存储用于加载`git`/` hg`类型的VCS存储库元数据的VCS克隆，并加快安装速度。

## cache-files-ttl

默认为`15552000`（6个月）。Composer缓存它下载的所有dist（zip，tar，..）包。这些在默认情况下未使用六个月后清除。该选项允许您调整此持续时间（以秒为单位）或通过将其设置为0将其完全禁用。

## cache-files-maxsize

默认为`300MiB`。Composer缓存它下载的所有dist（zip，tar，..）包。当垃圾收集周期性运行时，这是缓存可以使用的最大大小。较旧的（较少使用的）文件将首先被删除，直到缓存适合。

## bin-compat

默认为`auto`。确定要安装的二进制文件的兼容性。如果是这样的`auto`话，Composer只能在Windows上安装.bat代理文件。如果设置为，`full`那么针对Windows的两个.bat文件和针对基于Unix的操作系统的脚本将针对每个二进制文件安装。如果您在Linux虚拟机内运行Composer，但仍希望.bat代理可用于Windows主机操作系统，则此功能非常有用。

## prepend-autoloader

默认为`true`。如果`false`，Composer自动加载器不会被预先添加到现有的自动加载器中。有时这需要解决与其他自动加载器的互操作性问题。

## autoloader-suffix

默认为`null`。用作生成的Composer自动加载器后缀的字符串。当为空时，随机生成一个。

## optimize-autoloader

默认为`false`。如果`true`在转储自动加载器时始终进行优化。

## sort-packages

默认为`false`。如果`true`该`require`命令在`composer.json`添加新包时按名称保存包。

## classmap-authoritative

默认为`false`。如果`true`，Composer自动加载器将只加载类映射中的类。意味着`optimize-autoloader`。

## apcu-autoloader

默认为`false`。如果`true`，Composer自动加载器将检查APCu，并在启用扩展时使用它来缓存找到/未找到的类。

## github-domains

默认为`["github.com"]`。在github模式下使用的域列表。这用于GitHub Enterprise设置。

## github-expose-hostname

默认为`true`。如果`false`创建用于访问github API的OAuth令牌将具有日期而不是机器主机名。

## gitlab-domains

默认为`["gitlab.com"]`。GitLab服务器的域列表。这在您使用`gitlab`存储库类型时使用。

## notify-on-install

默认为`true`。Composer允许存储库定义通知URL，以便在安装该存储库中的软件包时得到通知。该选项允许您禁用该行为。

## discard-changes

默认为`false`可以是任何的`true`，`false`或`"stash"`。此选项允许您在非交互模式下设置处理脏更新的默认样式。`true`将永远放弃第三方库的变化，同时 `"stash"`将尝试存储和重新应用。如果您倾向于修改第三方库，请将其用于CI服务器或部署脚本。

## archive-format

默认为`tar`。Composer允许您在工作流需要创建专用归档格式时添加默认归档格式。

## archive-dir

默认为`.`。Composer允许您在工作流需要创建专用归档格式时添加默认归档目录。或者为了更简单的模块开发。

例：

```json
{
    "config": {
        "archive-dir": "/home/user/.composer/repo"
    }
}
```

## htaccess-protect

默认为`true`。如果设置为`false`，Composer将不会`.htaccess`在composer目录，缓存和数据目录中创建文件。

&larr; [存储库](05-repositories.md)  |  [社区](07-community.md) &rarr;
