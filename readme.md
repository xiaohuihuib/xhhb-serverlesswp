<p align="center"><img src="https://serverlesswp.com/wp-content/serverlesswp.png"></p>

WordPress 托管简直荒唐。

**低维护**、**低成本/免费** 的 WordPress 托管，运行在 Vercel、Netlify 或 AWS Lambda 上。

ServerlessWP 将 WordPress 放入 Serverless 函数中，将数据库放入一个文件里。部署此仓库即可试用。

随时关注 ServerlessWP 仓库的最新动态：[github.com/mitchmac/serverlesswp](https://github.com/mitchmac/serverlesswp)

![WordPress 7.0.4](https://img.shields.io/badge/version-7.0.4-blue?logo=wordpress&labelColor=white&logoColor=black) ![PHP 8.3.33](https://img.shields.io/badge/version-8.3.33-blue?logo=php&labelColor=white)

## 适用场景

**目前这是一个实验性项目。** 它面向内容型网站而非应用型网站：

✅ **非常适合：** 个人博客、文档、作品集、营销和小型企业网站、开发及预发布环境 —— 任何不频繁由多人同时更新的站点。

✅ **同样适合：无头/解耦 WordPress。** 仅将 WordPress 作为编辑后台和内容 API（REST 或 GraphQL）用于独立前端。

⚠️ **当以下情况请使用 MySQL 而非 SQLite：** 需要多人同时发布内容的网站、大量表单提交、电商、会员站点、论坛。SQLite+S3 和 SQLite+Blob 存在[有限的写入并发能力](#sqlite--对象存储)。


## 快速部署

**在 ServerlessWP 上运行 WordPress 最简单的方式是完全在 Vercel 上。** 此按钮会在设置期间创建一个私有的 [Vercel Blob](https://vercel.com/docs/vercel-blob) 存储，WordPress 则运行在保存在其中的 SQLite 数据库上。无需托管数据库、无需复制凭证、无需注册其他账户 —— 并且每个 git 分支都拥有自己的数据库。

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fxiaohuihuib%2Fserverlesswp&project-name=serverlesswp&repository-name=serverlesswp&stores=%5B%7B%22type%22%3A%22blob%22%2C%22access%22%3A%22private%22%2C%22envVarPrefix%22%3A%22SQLITE%22%7D%5D)

更多关于 [SQLite + Vercel Blob 如何工作](#sqlite--vercel-blob) 以及 [何时改用 MySQL](#mysql-数据库选项) 的信息。

其他部署方式（点击部署）：

- **[只是想试试？](https://serverlesswp.com/vercel-deploy)** 在 Vercel 上部署，使用 S3 上的临时 SQLite 数据库（几天后过期）。
- **[Netlify](https://app.netlify.com/start/deploy?repository=https://github.com/xiaohuihuib/serverlesswp)** 使用您自己的数据库（SQLite+S3 或 MySQL）。与 Vercel 的权衡：最大请求时长 10 秒（而非 60 秒）、手动分支配置，且分析/防火墙为付费附加功能。
- **AWS Lambda** 使用 Serverless Framework：`npm install && serverless deploy`

## 项目目标

🌴 简化 WordPress 托管。使用 Serverless 函数代替服务器，降低维护成本。

💲 小型 WordPress 网站不应该花费太多托管费用。**Vercel、Netlify 和 AWS 均有免费额度**。

🔓 广泛支持 WordPress 插件和主题。这里没有任何人为限制。

⚡ 充分利用缓存和内容分发网络，打造极速网站。

🌎 降低 WordPress 网站的碳足迹。

🤝 乐于助人的社区。在[讨论区](https://github.com/mitchmac/ServerlessWP/discussions)分享您的成功、想法或遇到的问题。

## 部署 ServerlessWP

### 1. 将此仓库部署到 Vercel、Netlify 或 AWS。
上面的链接之一可以帮助您开始。您只需要一个 GitHub 账户。

### 2. 设置数据库。
**推荐使用 Vercel Blob 或 S3，因为这是最快上手的方案，维护最少：无需预置任何东西、无需 24/7 运行，而且在 Vercel 上完全不需要复制凭证。** MySQL 同样受支持，并且对于前面提到的某些站点类型仍然是更好的选择 —— 请参见 [MySQL](#mysql-数据库选项)。

如果您使用了上面的 Vercel 按钮，那么您已经完成了：它创建的 Blob 存储就是您的数据库。直接跳到步骤 3。

否则，请在下方选择您的数据库 —— [SQLite + 对象存储](#sqlite--对象存储) 或 [MySQL](#mysql-数据库选项) —— 然后回来继续配置上传。

无论选择哪个，您都需要通过环境变量进行设置。关于如何管理环境变量，请参见 [Vercel 文档](https://vercel.com/docs/concepts/projects/environment-variables) 和 [Netlify 文档](https://docs.netlify.com/environment-variables/overview/)。**请记住**，如果在初始部署后更改环境变量，需要重新部署项目。

### 3. 使用 S3 的文件和媒体上传（可选，可稍后完成）
可以使用包含的 WP Offload Media Lite for Amazon S3 插件启用文件和媒体上传。S3 设置详情可参见[此处](https://deliciousbrains.com/wp-offload-media/doc/amazon-s3-quick-start-guide/)。wp-config.php 文件已配置为使用以下环境变量供插件使用：
- S3_KEY_ID
- S3_ACCESS_KEY

## SQLite + 对象存储
WordPress 通常使用 MySQL（或 MariaDB）数据库运行。这意味着需要托管一个 24/7 运行的数据库。

WordPress 社区成员开发了 [SQLite 数据库](https://github.com/WordPress/sqlite-database-integration) 选项。凭借最近实现的*有条件写入*对象存储（Vercel Blob 或 S3 及兼容 S3 的存储桶）的能力，为 ServerlessWP 实现了一个去中心化且无服务器的数据层。

如果您对工作原理感兴趣，可以查看 [SQLite+S3 逻辑示意图](https://github.com/mitchmac/ServerlessWP/wiki/How-does-SQLite-with-S3-work-with-ServerlessWP%3F)。

ServerlessWP 同时支持 SQLite 和 MySQL 作为数据库选项。它们的一些权衡如下：

| SQLite + 对象存储 | MySQL |
|---|---|
| 🕑 按需使用   | 24/7 托管 |
| 💲 按使用量计费（有免费额度） | 按月收费（部分有限免费额度） |
| 🧩 部分插件不兼容 | 完全插件兼容 |
| ♾️ 有限的数据库更新并发能力 | 并发限制较少 |
| ✔️ 博客、开发站点、文档、单人编辑站点 | 任何站点 |

使用 SQLite 与 ServerlessWP 的主要权衡在于：
- 如果多个底层 Serverless 函数同时处理请求并对数据库进行更改，那么竞争请求可能会失败。因此，不适合多编辑同时工作或接收大量表单提交的站点。

### SQLite + Vercel Blob

最简单的选项。在 Vercel 上，上面的部署按钮会在设置期间为您创建一个私有的 [Vercel Blob](https://vercel.com/docs/vercel-blob) 存储 —— 无需创建存储桶或 IAM 凭证。git 分支名称会被附加到存储名称中，因此预览部署各自拥有独立的数据库。

| SQLite+Vercel Blob | |
|---|---|
| BLOB_STORE_ID | 存储数据库的 store ID - Vercel 在连接 store 时自动添加 |
| SQLITE_BLOB_STORE_ID | 可选：指定要使用的 store ID，用于通过前缀 `SQLITE` 创建的环境变量连接 |
| SQLITE_BLOB_READ_WRITE_TOKEN | 可选：静态读写令牌，用于具有该令牌的 store |
| SQLITE_BLOB_PATHNAME | 可选：数据库的基本名称 - 默认为 `wp-sqlite` |

连接一个 store 就是全部设置。Vercel 会将 `BLOB_STORE_ID` 添加到项目中，并为每次部署生成一个短期有效的 `VERCEL_OIDC_TOKEN`，Blob SDK 将两者配对以进行身份验证。要在现有项目上进行此设置，请从“Storage”选项卡创建一个**私有**访问的 store 并连接它 —— 无需复制任何内容。

使用静态 `BLOB_READ_WRITE_TOKEN` 的 store 也可以作为 `SQLITE_BLOB_READ_WRITE_TOKEN` 使用。不带前缀的名称不会被使用 —— 那是用于媒体上传的 store 的令牌，而这些 store 是公共的，因此对其进行的每次私有写入都会失败。

请将数据库 store 与用于媒体上传的任何 store 分开：数据库必须保持私有且不被缓存，而上传文件则希望公共读取和 CDN 缓存。一个用于上传连接的 store 也会设置 `BLOB_STORE_ID`，因此如果您同时连接两者，请将数据库 store 的 ID 命名为 `SQLITE_BLOB_STORE_ID`。

### SQLite + S3

适用于任何地方 —— Netlify、AWS 或 Vercel —— 以及任何兼容 S3 的存储桶，包括 Cloudflare R2。设置一个**私有**存储桶并使用以下环境变量：

| SQLite+S3 | |
|---|---|
| SQLITE_S3_BUCKET | 您创建的存储桶名称 |
| SQLITE_S3_API_KEY | 访问存储桶的 API key |
| SQLITE_S3_API_SECRET | 访问存储桶的 API secret |
| SQLITE_S3_REGION | 存储桶所在的区域 —— 请将其创建在靠近您的 Serverless 函数的位置 |
| SQLITE_S3_ENDPOINT | 可选：用于更新存储桶地址，例如 Cloudflare R2 的地址 |

## MySQL 数据库选项

当您需要完整的插件兼容性或超过两人同时写入时，这是正确的选择。[TiDB](https://www.pingcap.com/tidb-cloud-serverless/) 提供了具有慷慨免费额度的云 MySQL 数据库。

创建数据库后，使用以下凭证设置这些环境变量。`wp-config.php` 会自动配置以使用它们进行连接。

|  |  |
|---|---|
| DATABASE | 您创建的数据库名称 |
| USERNAME | 访问数据库的数据库用户 |
| PASSWORD | 数据库用户的密码 |
| HOST | 访问数据库的地址 |
| TABLE_PREFIX | 可选：用于数据库表的前缀 |

## 使用哪个数据库

最显式配置的选项优先，因此为媒体添加 Blob store 不会取代已有数据库：

1. **MySQL** —— 同时设置了 `DATABASE`、`USERNAME`、`PASSWORD` 和 `HOST`
2. **SQLite + S3** —— 设置了 `SQLITE_S3_BUCKET`
3. **SQLite + Vercel Blob** —— 在 Vercel 上设置了 `BLOB_STORE_ID`（或 `SQLITE_BLOB_STORE_ID`，或 `SQLITE_BLOB_READ_WRITE_TOKEN`）
4. 否则显示设置页面

## 自定义 WordPress
- WordPress 及其文件位于 `/wp` 目录中。您可以在 `wp-content` 的相应目录中添加插件或主题，然后将文件提交到仓库以便重新部署。
- 像 [Cache-Control](https://wordpress.org/plugins/cache-control/) 这样的插件可以通过 s-maxage 指令启用 CDN 缓存，使您的站点极速加载。请参阅 [Vercel 边缘缓存](https://vercel.com/docs/concepts/edge-network/caching) 或 [Netlify 缓存头](https://docs.netlify.com/edge-functions/optional-configuration/#supported-headers)。

## 自定义 ServerlessWP
- `netlify.toml` 或 `vercel.json` 是我们配置 `/api/index.js` 来处理所有请求的地方。
- [mitchmac/serverlesswp-node](https://github.com/mitchmac/serverlesswp-node) 用于运行 PHP 并处理请求。
- 您可以通过 `api/index.js` 中的 `event` 对象修改传入请求。您也可以在那里修改 WordPress 的 `response` 对象。ServerlessWP 有一个基本的插件系统来实现这一点。查看 `/api/index.js` 获取提示。

## 获得帮助
需要帮助安装 ServerlessWP？[发起一个讨论](https://github.com/mitchmac/ServerlessWP/discussions) 或 [给我发聊天](https://serverlesswp.com/chat)。

## 贡献
- 使用 ServerlessWP 并[报告您遇到的任何问题](https://github.com/mitchmac/ServerlessWP/issues) 是一种很好的帮助方式。
- 传播这个项目！

## 许可证
GNU General Public License v3.0
