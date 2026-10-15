# Typecho Says Plugin 🗨️

[![License](https://img.shields.io/badge/License-Mulan_PSL_2-blue.svg)](LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.2+-green.svg)](https://typecho.org)

> 在 Typecho 博客系统中实现类似微博的说说功能,支持API提交、后台管理和前端展示

## 当前版本
V2.0.0

## 功能特性

- 📝 类似微博/闪念胶囊的说说,支持 Markdown 渲染与图片附件
- 🏷️ `#标签` 自动解析,前端点击标签即可过滤列表(可自建标签云)
- 🖼️ 图片上传支持 本地 / Backblaze B2 / 又拍云 三种存储
- 🔌 兼容 memos 的开放 API,可配合任意客户端远程提交
- 🎵 网易云音乐、Bilibili、豆瓣卡片等链接自动解析
- 📱 来源小尾巴识别,支持自定义匹配规则
- 🗄️ 兼容 MySQL / SQLite / PostgreSQL

## 使用方法

- 下载后将文件夹改名为Says,放至 `/usr/plugins/` 目录下

- 在后台启用插件

- 在要使用的页面上,添加以下代码,即可食用

```php
<?php \TypechoPlugin\Says\Plugin::render(10, '#says', '/memos/');?>
```
10为显示条数,#says为显示的容器,/memos/为API接口地址

## 高级选项

- 如果你还有特殊的需求,还可以指定相关的样式和JS

```php
<?php 
    \TypechoPlugin\Says\Plugin::render(
        10, 
        '#says', 
        '/memos/', 
        [
            'css' => '', 
            'markdown' => 'https://cdnjs.cloudflare.com/ajax/libs/marked/15.0.7/marked.min.js', 
            'js' => './says.js'
        ]
    );
?>
```
这样配置,系统将会不加载默认样式,并加载公共的markd库和本地的JS文件

后台菜单位置:后台 > 管理 > 说说

** 令牌一定要生成一个足够长的,可以用32位md5生成

## 后台设置

| 配置项 | 说明 |
|---|---|
| **是否开启API远程提交** | 关闭后只能从后台发布,相关 API 直接返回 404 |
| **API访问令牌 / 用户ID** | API 认证凭证。令牌建议 32 位以上随机字符串(如 md5),用户ID 填 `后台 > 用户 > 管理` 中对应的 ID(非用户名) |
| **图床管理 / 图床参数设置** | 见下方"图片/附件功能",敏感密钥不要写入此处 |
| **附件存储路径** | 仅支持 PHP `date()` 格式符,如 `Y/md` |
| **文件重命名规则 / 最大上传文件大小 / 可上传文件类型** | 见下方"附件相关设置" |
| **来源名称(小尾巴)** | 识别 UA 来源,格式 `名称\|要包含的字符串`,每行一个,按从上往下优先级匹配 |
| **默认来源** | 全部小尾巴未匹配时显示:留空=不显示,1=操作系统,2=浏览器,3=操作系统+浏览器,其他=自定义文本 |
| **禁用插件时是否删除数据表** | 谨慎选择,删除后数据不可恢复 |

## API 接口

接口前缀为 `api/v1`,下文以 `/memos/` 为例(即 `render()` 第三个参数传入的地址):

| 方法 | 地址 | 说明 |
| --- | --- | --- |
| GET | `/memos/api/v1/memo` | 获取已发布说说列表。参数:`limit`(默认8)、`page`(默认1)、`tag`(按标签过滤) |
| GET | `/memos/api/v1/tags` | 获取热门标签(按使用次数降序)。参数:`limit`(默认20) |
| GET | `/memos/api/v1/auth/status` | 校验令牌并返回说说用户信息(昵称/头像) |
| POST | `/memos/api/v1/memos` | 发布说说。JSON 参数:`content`、`visibility`(public/private)、`resourceIdList` 或 `attachmentIds`(附件 ID 数组,兼容 memos) |
| POST | `/memos/api/v1/attachment` | 上传附件(兼容 memos)。JSON 参数:`content`(文件 base64)、`filename`、`type` |

除获取列表与标签外,其它接口都需在请求头携带令牌:

```
Authorization: Bearer <你的令牌>
```

发布接口返回 `status: 1` 表示成功;令牌错误或未开启远程提交时返回 401 / 404。

## 标签(Hashtag)功能

在说说内容中直接写 `#标签名` 即可,后台发布/编辑时会自动解析并建立关联:

- 标签由 `#` 加一串字符组成,不能包含 `#`、空格和标点 `! , . ; : ? " ' ( )`,且后面必须紧跟空格或到行尾
- 代码块、行内代码以及 Markdown 链接文字中的 `#` 不会被误判为标签
- 前端渲染为可点击的标签,点击后自动按该标签过滤说说列表,再点一次取消过滤
- 删除说说时自动反扣标签计数,引用数为 0 的孤儿标签会被清理
- 通过 `GET /memos/api/v1/tags` 获取热门标签(含 `count`),可在主题中自建标签云

## 图片/附件功能

插件支持将图片上传到本地或云图床,通过markdown语法插入说说内容,前端正常渲染显示。

### 上传方式

- **后台**:发表说说时点击"图片"按钮选择图片上传,上传成功后自动插入 `![图片](图片地址)` 到内容框;未使用的图片会显示在发布框下方暂存区,可点击插入、预览或删除
- **API**:通过 `POST /memos/api/v1/attachment` 上传(兼容 memos 接口),发布说说时通过 `resourceIdList` / `attachmentIds` 关联附件

### 图床类型

在 **插件设置 > 图床管理** 选择:

| 类型 | 说明 |
|---|---|
| **本地(Local)** | 文件固定存放在站点 `usr/uploads` 目录下 |
| **Backblaze B2** | 上传到 B2 存储桶 |
| **又拍云(Upyun)** | 上传到又拍云空间 |

图床参数(storageConfig)以 JSON 格式填写在插件设置中,各图床所需字段不同:

- **local**:一般留空(存储目录固定为 `usr/uploads`)
- **backblaze**:`{"bucketId":"xxx","bucketName":"xxx","path":"says/","domain":""}`(path为桶内路径前缀,domain为自定义域名,可选)
- **upyun**:`{"bucket":"xxx","endpoint":"","url":""}`(endpoint为API端点,url为自定义访问域名,均可选)

### 密钥配置(重要)

**密钥(applicationKey/密码等敏感信息)不要写入数据库配置,请写入站点根目录的 `config.inc.php`:**

```php
// Backblaze B2
define('SAYS_B2_KEY_ID', '你的 KeyID');
define('SAYS_B2_APPLICATION_KEY', '你的应用密钥');

// 又拍云
define('SAYS_UPYUN_OPERATOR', '操作员名');
define('SAYS_UPYUN_PASSWORD', '操作员密码');
```

不定义这些常量而选择对应图床时,上传会直接报错提示缺少配置。

### 附件相关设置

| 配置项 | 说明 |
|---|---|
| **附件存储路径** | 附件子目录格式,仅支持 PHP `date()` 格式符,如 `Y/md`(年/月日)。Local 图床固定存于 `usr/uploads` 下,云图床为桶内路径 |
| **文件重命名规则** | `UUID`(32位唯一)或 `唯一ID`(uniqid,微秒级) |
| **最大上传文件大小** | 单位 M,留空表示不限 |
| **可上传文件类型** | 逗号分隔的扩展名白名单,默认 `jpg,jpeg,png,gif,webp,avif` |

### 附件清理

上传但未使用(未关联到任何说说)的图片会一直显示在发布框下方暂存区,可通过右侧 **X** 手动删除。已关联的图片在删除说说时自动清理(引用计数归零才删除物理文件)。

## 版本更新

### 2.0.0
 - 重构安装方式:建表 SQL 拆分为独立脚本(sql/ 目录),支持 MySQL/SQLite/PostgreSQL
 - 修复插件激活时整段 SQL 文件无法被 Typecho Db 适配器执行的问题(逐条执行)
 - 使用木兰宽松许可证第2版(Mulan PSL v2)
 - 新增 `#标签` 功能:内容自动解析、后台关联管理、前端点击过滤、热门标签接口(`GET /api/v1/tags`)
 - 新增附件关联管理:说说与附件多对多关联,发布接口支持 `resourceIdList`/`attachmentIds`

### 1.3.0
 - 新增图片/附件上传功能,支持本地/Backblaze B2/又拍云三种存储
 - 密钥读写分离,敏感配置写入 config.inc.php,不进数据库
 - 发布框图片工具栏:选图上传、自动插入markdown、未使用图片暂存区
 - 附件引用计数(md5去重,多条说说可共享同一图片)
 - 附件存储路径/重命名规则/大小/类型白名单可配置
 - 兼容 memos 的附件上传接口

### 1.2.5
 - 修复后台修改时的时区错误
 - 修改前台的网易云音乐和Bilibili的解析,链接显示文字和链接不同的时候,不会解析,防止只想引用链接而被强制解析成播放器的尴尬,即现在的解析支持的是

   |链接写法|是否解析|
   |-|-|
   |https://bilibili.com/video/BV12345|会解析|
   |\[https://bilibili.com/video/BV12345\]\(https://bilibili.com/video/BV12345\)|会解析|
   |\[https://bilibili.com/video/BV12345\]\(//bilibili.com/video/BV12345\)|不会解析|
   |\[点我跳转\](//bilibili.com/video/BV12345)|不会解析|


### 1.2.4
 - 兼容 Sqlite 数据库
 - 添加是否在禁用插件时删除表的选项

### 1.2.3
 - 修复了豆瓣评分显示错误的BUG

### 1.2.2
 - 修复了豆瓣非game类链接错误的问题

### 1.2.0
 - 添加了自定义js和css功能

## 特别感谢

[Typecho - https://typecho.org](https://typecho.org)

[Marked - https://marked.js.org](https://marked.js.org)

[蚂蚱 - https://qiyu.pub/](https://qiyu.pub/)

## 版权所有
本项目遵循 [木兰宽松许可证第2版(Mulan PSL v2)](LICENSE),请自由使用。