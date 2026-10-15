# Typecho Says Plugin 🗨️

[![License](https://img.shields.io/badge/License-Mulan_PSL_2-blue.svg)](LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.2+-green.svg)](https://typecho.org)

> 在 Typecho 博客系统中实现类似微博的说说功能,支持API提交、后台管理和前端展示

## 当前版本
V2.0.0

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

## 图片/附件功能

插件支持将图片上传到本地或云图床,通过markdown语法插入说说内容,前端正常渲染显示。

### 上传方式

- **后台**:发表说说时点击"图片"按钮选择图片上传,上传成功后自动插入 `![图片](图片地址)` 到内容框;未使用的图片会显示在发布框下方暂存区,可点击插入、预览或删除
- **API**:通过 `POST /memos/api/v1/attachment` 上传(兼容 memos 接口)

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