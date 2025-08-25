# Typecho Says Plugin 🗨️

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Typecho](https://img.shields.io/badge/Typecho-1.2+-green.svg)](https://typecho.org)

> 在 Typecho 博客系统中实现类似微博的说说功能，支持API提交、后台管理和前端展示

## 当前版本
V1.2.3

## 使用方法

- 下载后将文件夹改名为Says，放至 `/usr/plugins/` 目录下

- 在后台启用插件

- 在要使用的页面上，添加以下代码，即可食用

```php
<?php \TypechoPlugin\Says\Plugin::render(10, '#says', '/memos/');?>
```
10为显示条数，#says为显示的容器，/memos/为API接口地址

## 高级选项

- 如果你还有特殊的需求，还可以指定相关的样式和JS

```php
<?php \TypechoPlugin\Says\Plugin::render(10, '#says', '/memos/', ['css' => '', 'markdown' => 'https://cdnjs.cloudflare.com/ajax/libs/marked/15.0.7/marked.min.js', 'js' => './says.js']);?>
```
这样配置，系统将会不加载默认样式，并加载公共的markd库和本地的JS文件

后台菜单位置：后台 > 管理 > 说说

** 令牌一定要生成一个足够长的，可以用32位md5生成

## 版本更新
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
本项目遵循MIT协议，请自由使用。