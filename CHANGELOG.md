# 更新日志 (Changelog)

所有版本的变更记录。格式参考 [Keep a Changelog](https://keepachangelog.com/),版本号遵循语义化递增。

## 2.2.1

### 变更

- 来源机制:移除 UA 自动识别(`getSource`/`getOS`/`getBrowser`/`getPlatform` 全部删除),来源仅取发布时手动填写的值,留空则使用设置里的"默认来源"文本,再留空即不显示(后台列表显示"本站")
- 移除"来源名称(小尾巴)"配置项;"默认来源"配置语义改为纯文本默认值(原 1/2/3 系统/浏览器选项随 UA 识别一并移除)
- 后台上传附件不再做文件大小校验(仍保留扩展名白名单校验)

## 2.2.0

### 新增

- **memos 浏览器扩展(memos-bber)兼容**:服务端伪装为 memos 0.21.0(legacy),扩展自动识别后走 legacy 流程对接
  - 新增 `GET /api/v1/instance/profile`:返回版本号供扩展识别服务端代际
  - 新增 `POST /api/v1/resource/blob`:memos legacy 的 multipart 附件上传(字段 `file`,真实字节进度,免 base64 膨胀)
  - 单数 `POST /api/v1/memo` 网关:legacy 发布(请求体携带 `resourceIdList`,附件关联零新增逻辑);同路径 GET 仍为列表,按 HTTP 方法分发
  - 新增 `GET /api/v1/auth/me`:扩展认证阶梯第一站直接命中,省去两次 404 探测
- **内容标记**:发布内容中可写 `[来源: xxx]` / `[位置: xxx]`,API 自动提取入库并从内容中剥离(兼容全角冒号;多个标记取第 1 个、全部移除;取值优先级 JSON 显式参数 > 内容标记 > UA 识别)
- **发布响应返回 memos 兼容的 memo 实体**:`{status, memo: {id, name, uid, content, visibility, created_at, updated_at, resources[]}}`,`resources[]` 含 `externalLink`(附件公开地址,扩展预览直接可用)

### 变更

- **图片展示模型切换(memos 模式)**:正文不再包含图片 markdown
  - 附件通过 `resources` / `attachments` 字段随 API 返回(含 `thumb` 缩略图)
  - 前台图片宫格由附件列表渲染,不再从内容中提取图片;旧帖正文内嵌图片保持内联兼容且不重复展示
  - 后台上传不再向内容框插入 `![图片](url)`;暂存区文件名点击改为预览
  - 后台说说列表直接显示附件窄条(文件名 + 眼睛图标,点击放大原图,无删除);发布/编辑响应携带 `resources` 供行内即时更新

### 修复

- 后台说说列表分页条无样式(首次超过 10 条时分页条出现,呈默认竖排列表):补充分页条横排方格样式
- 修复图片渲染 `title/alt` 引号错乱(2.1.1 漏记,实际在本版本周期内修复)

### 移除

- 冗余接口:`GET /auth/status`(由 `/auth/me` 取代)、`POST /memos` 复数发布与 `POST /attachments` 复数上传(legacy 单数 `/memo` 与 `/resource/blob` 已完整覆盖,接口面收敛为 6 条)

## 2.1.1

- 前台图片接入 Fancybox:缩略图以锚点包裹,点击弹出原图,按说说分组(每条说说一个独立相册,需主题加载 Fancybox)
- 图片展示改为微博式宫格:多图固定 100×100 方格排列(object-fit 裁方,白边描边卡片样式),单图放大显示,图片移至文字下方
- 后台说说列表不再解析 markdown,直接显示原始文本(保留换行,不再加载 marked 库,顺带修复 XSS 隐患)

## 2.1.0

- 新增图片缩略图功能:上传时自动生成 200 见方小图,信息流加载小图,点击灯箱看原图,原图保留
- 缩图引擎 Imagick 优先、GD 兜底,输出与原图同格式,EXIF 方向自动修正,GIF 动图自动跳过
- 又拍云支持参数模式缩图(URL 拼图片处理参数,不生成本地文件)
- 附件上传改为先查重再上传,重复图片零网络请求
- 附件表新增 thumb 字段,激活时自动补列并回填老数据
- 修复 SQLite 建表脚本尾逗号导致新装失败的问题
- 修复 PostgreSQL 适配器名识别错误导致激活失败的问题

## 2.0.0

- 重构安装方式:建表 SQL 拆分为独立脚本(sql/ 目录),支持 MySQL/SQLite/PostgreSQL
- 修复插件激活时整段 SQL 文件无法被 Typecho Db 适配器执行的问题(逐条执行)
- 使用木兰宽松许可证第2版(Mulan PSL v2)
- 新增 `#标签` 功能:内容自动解析、后台关联管理、前端点击过滤、热门标签接口(`GET /api/v1/tags`)
- 新增附件关联管理:说说与附件多对多关联,发布接口支持 `resourceIdList`/`attachmentIds`

## 1.3.0

- 新增图片/附件上传功能,支持本地/Backblaze B2/又拍云三种存储
- 密钥读写分离,敏感配置写入 config.inc.php,不进数据库
- 发布框图片工具栏:选图上传、自动插入markdown、未使用图片暂存区
- 附件引用计数(md5去重,多条说说可共享同一图片)
- 附件存储路径/重命名规则/大小/类型白名单可配置
- 兼容 memos 的附件上传接口

## 1.2.5

- 修复后台修改时的时区错误
- 修改前台的网易云音乐和Bilibili的解析,链接显示文字和链接不同的时候,不会解析,防止只想引用链接而被强制解析成播放器的尴尬,即现在的解析支持的是

  |链接写法|是否解析|
  |-|-|
  |https://bilibili.com/video/BV12345|会解析|
  |\[https://bilibili.com/video/BV12345\]\(https://bilibili.com/video/BV12345\)|会解析|
  |\[https://bilibili.com/video/BV12345\]\(//bilibili.com/video/BV12345\)|不会解析|
  |\[点我跳转\](//bilibili.com/video/BV12345)|不会解析|

## 1.2.4

- 兼容 Sqlite 数据库
- 添加是否在禁用插件时删除表的选项

## 1.2.3

- 修复了豆瓣评分显示错误的BUG

## 1.2.2

- 修复了豆瓣非game类链接错误的问题

## 1.2.0

- 添加了自定义js和css功能
