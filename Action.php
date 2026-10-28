<?php
namespace TypechoPlugin\Says;

use Typecho\Widget;
use Typecho\Db;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

// 引入公共函数
require_once 'function.php';

class Action extends Widget {
    
    private $db;
    private $options;
    private $tablePrefix;

    /**
     * Action 构造函数
     *
     * @param Request $request Typecho 请求对象
     * @param Response $response Typecho 响应对象
     */
    public function __construct($request, $response) {
        // 必须调用父类构造函数
        parent::__construct($request, $response); 
        // 数据库实例
        $this->db = Db::get();
        // 插件配置
        $this->options = Widget::widget('Widget_Options')->plugin('Says');
        // 表名
        $this->saysTable = $this->db->getPrefix() . 'says';
    }
    
    /**
     * 单数 /memo 路由网关(GET 列表 / POST legacy 发布)
     *
     * memos legacy(0.15-0.21)的列表与发布共用单数路径,而 Typecho 路由不区分
     * HTTP 方法,无法注册两条同路径路由,这里按方法分发。
     */
    public function memoGateway() {
        // POST:legacy 扩展发布(请求体携带 resourceIdList,插件关联逻辑原样生效)
        if ($this->request->isPost()) {
            $this->createMemo();
            return;
        }
        // 其余方法:走原有的列表逻辑
        $this->getMemos();
    }

    /**
     * 实例信息 (GET /api/v1/instance/profile)
     *
     * memos 扩展通过版本号识别服务端代际:返回 0.21.0 使其按 legacy 流程对接,
     * 即 multipart /resource/blob 上传 + 单数 /memo 发布(携带 resourceIdList),
     * 与插件现有附件管线最贴合(发布关联零新增逻辑,上传有真实字节进度)。
     */
    public function profile() {
        // 无需鉴权,memos 的实例信息本就是公开的
        $this->response->throwJson([
            'profile' => [
                'version' => '0.21.0',
                'mode' => 'says',
            ],
        ]);
    }

    /**
     * legacy multipart 附件上传 (POST /api/v1/resource/blob)
     *
     * memos legacy 的上传接口:multipart 表单,字段名固定为 file。
     * 与 /attachments 的 base64 方式不同,multipart 直接拿临时文件走附件管线,
     * 省去 base64 编码(体积 -33%),扩展端也有真实字节进度。
     */
    public function uploadResourceBlob() {
        // 如果插件停用,直接返回404
        if (!$this->options->isOpen) throw new \Exception(_t('接口不存在'), 404);
        try {
            // 获取 token 并反查对应用户
            $userId = $this->resolveCurrentUser();
            // 鉴权失败
            if (!$userId) $this->response->throwJson(['status' => 0, 'message' => 'Unauthorized']);
            // memos legacy 上传为 multipart 表单,字段名固定为 file
            if (!isset($_FILES['file'])) {
                return $this->response->throwJson(['status' => 0, 'message' => '未收到上传文件']);
            }
            // 上传文件
            $file = $_FILES['file'];
            // 上传出错或文件名为空
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || empty($file['name'])) {
                return $this->response->throwJson(['status' => 0, 'message' => '上传文件无效']);
            }
            // 扩展名白名单
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExt, getAllowedFileTypes())) {
                return $this->response->throwJson(['status' => 0, 'message' => '不允许的文件类型: ' . $fileExt]);
            }
            // 目标路径,直接给临时文件走附件管线(无需 base64)
            $target = generateTargetPath($file['name']);
            $attachment = \TypechoPlugin\Says\Attachment::upload($file['tmp_name'], $target, false);
            // memos legacy 形态的 resource 实体
            $this->response->throwJson([
                'id' => (int)$attachment['id'],
                'name' => 'resources/' . (int)$attachment['id'],
                'filename' => $file['name'],
                'size' => (int)$attachment['size'],
                'type' => $attachment['mime'],
                'url' => $attachment['url'],
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取所有 Memo (GET /api/v1/memo)
     * 参数类型 limit=8&page=1
     */
    public function getMemos() {
        // 每页条数,最小 1,最大 50(拉取过量数据会拖垮接口)
        $limit = min(50, max(1, $this->request->filter('int')->get('limit', 8)));
        // 默认第 1 页,最小 1
        $page = max(1, $this->request->filter('int')->get('page', 1));   
        // 偏移量
        $offset = ($page - 1) * $limit;
        // 可选标签过滤: ?tag=图片
        $tag = trim((string)$this->request->get('tag', ''));
        try {
            // 基础查询
            $query = $this->db->select()->from($this->saysTable);
            // 计数查询
            $countQuery = $this->db->select('COUNT(*) as count')->from($this->saysTable);

            // 按标签过滤:JOIN 关联表 + 标签表,取含该标签的说说
            if ($tag !== '') {
                // 查标签
                $tagRow = $this->db->fetchRow($this->db->select()
                    ->from($this->db->getPrefix() . 'says_hashtag')
                    ->where('name = ?', $tag)
                    ->limit(1));
                // 标签不存在则返回空
                if (!$tagRow) {
                    $this->response->throwJson(['status' => 1, 'message' => 'ok', 'data' => []]);
                }
                // 按标签过滤说说 id
                $sayIds = (array)$this->db->fetchAll($this->db->select('say_id')
                    ->from($this->db->getPrefix() . 'says_hashtag_meta')
                    ->where('hashtag_id = ?', (int)$tagRow['id']));
                // 提取 id 列表
                $ids = array_map(function ($row) { return (int)$row['say_id']; }, $sayIds);
                // 无说说直接返回空
                if (empty($ids)) {
                    $this->response->throwJson(['status' => 1, 'message' => 'ok', 'data' => []]);
                }
                // 加上 id 过滤
                $query->where('id IN ?', $ids);
                $countQuery->where('id IN ?', $ids);
            }

            // 只在发布状态下,按时间倒序分页
            $query->where('status = ?', 1)->order('created_at', Db::SORT_DESC)->offset($offset)->limit($limit);
            // 查询
            $memos = (array)$this->db->fetchAll($query);
            // 获取总数
            $total = (int)$this->db->fetchObject($countQuery->where('status = ?', 1))->count;
            // 格式化数据
            $data = [];
            foreach ($memos as $memo) {
                // 获取该说说关联的附件
                $attachments = \TypechoPlugin\Says\Attachment::getBySay((int)$memo['id']);
                // 格式化附件列表
                $attachmentList = [];
                foreach ($attachments as $att) {
                    $attachmentList[] = [
                        'id' => (int)$att['id'],
                        'url' => $att['url'],
                        'thumb' => !empty($att['thumb']) ? $att['thumb'] : $att['url'],
                        'name' => $att['name'],
                        'size' => (int)$att['size'],
                        'mime' => $att['mime'],
                        'douban' => (int)!empty($att['douban']),
                    ];
                }
                // 组装数据
                $data[] = [
                    'uid' => $memo['uuid'],
                    'content' => $memo['content'],
                    'created_at' => intval($memo['created_at']),
                    'updated_at' => intval($memo['updated_at']),
                    'from' => !empty($memo['source']) ? trim($memo['source']) : '',
                    'position' => $memo['position'] ?? '',
                    'attachments' => $attachmentList
                ];
            }
            // 返回数据
            $this->response->throwJson(['status' => 1, 'message' => 'ok', 'data' => $data]);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取所有标签 (GET /memos/api/v1/tags)
     * 参数类型 limit=20(可选,默认20)
     */
    public function getTags() {
        // 每页条数
        $limit = $this->request->filter('int')->get('limit', 20);
        try {
            // 查询热门标签(按 count 降序)
            $tags = \TypechoPlugin\Says\Hashtag::getHot(max(1, $limit));
            // 格式化
            $data = [];
            foreach ($tags as $tag) {
                $data[] = [
                    'name' => $tag['name'],
                    'count' => (int)$tag['count'],
                ];
            }
            // 返回数据
            $this->response->throwJson(['status' => 1, 'message' => 'ok', 'data' => $data]);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 当前用户 (GET /api/v1/auth/me)
     *
     * memos 0.26+ 的认证端点,扩展的认证阶梯第一站就是它;
     * 命中后可省去后面两次 404 探测,也便于扩展回显身份信息。
     */
    public function authMe() {
        // 如果插件停用，直接返回404
        if (!$this->options->isOpen) throw new \Exception(_t('接口不存在'), 404);
        try {
            // 获取 token 并反查对应用户(多用户+多令牌)
            $userId = $this->resolveCurrentUser();
            // 鉴权失败
            if (!$userId) $this->response->throwJson(['status' => 0, 'message' => 'Unauthorized']);
            // 查询用户信息
            $user = $this->db->fetchRow($this->db->select('screenName', 'mail')->from($this->db->getPrefix() . 'users')->where('uid = ?', $userId));
            // 用户不存在则抛出错误
            if (!$user) $this->response->throwJson(['status' => 0, 'message' => _t('指定的说说用户不存在.')]);
            // 生成头像URL (使用Gravatar)
            $avatarUrl = __TYPECHO_GRAVATAR_PREFIX__ . md5(strtolower(trim($user['mail']))) . '?s=64&d=identicon';
            // memos 0.26+ 形态:user 实体(name 为 users/{id},扩展靠它拼资源引用)
            $this->response->throwJson([
                'user' => [
                    'id' => $userId,
                    'name' => 'users/' . $userId,
                    'nickname' => $user['screenName'],
                    'avatar' => $avatarUrl,
                ],
            ]);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * 创建 Memo (POST /memos/api/v1/memo)
     * 创建一个 Memo
     * 参数类型 content: 创建内容
     */
    public function createMemo() {
        // 如果插件停用，直接返回404
        if (!$this->options->isOpen) throw new Exception(_t('接口不存在'), 404);
        try {
            // 获取 token 并反查对应用户(多用户+多令牌)
            $userId = $this->resolveCurrentUser();
            // 鉴权失败
            if (!$userId) $this->response->throwJson(['status' => 0, 'message' => 'Unauthorized']);
            // 获取 JSON 请求数据
            $data = json_decode(file_get_contents('php://input'), true);
            // 验证 JSON 解析是否成功
            if (json_last_error() !== JSON_ERROR_NONE) return $this->response->throwJson(['status' => 0, 'message' => 'Invalid JSON data']);
            // 获取请求数据
            $content = isset($data['content']) ? trim($data['content']) : '';
            // 提取UA
            $agent = $this->request->getAgent();
            // 从内容中提取 [来源: xxx] / [位置: xxx] 标记
            // (memos 客户端没有来源/位置参数,约定用内容标记携带;兼容全角冒号)
            $markerSource = $markerPosition = '';
            // 提取来源标记，兼容全角冒号
            if (preg_match('/\[来源[:：]\s*([^\]]*)\]/u', $content, $mSource)) $markerSource = trim($mSource[1]);
            // 提取位置标记，兼容全角冒号
            if (preg_match('/\[位置[:：]\s*([^\]]*)\]/u', $content, $mPos)) $markerPosition = trim($mPos[1]);
            // 内容中出现标记就一律移除(空值标记也剥掉,值视为未提供走 UA 识别)
            if (strpos($content, '[来源') !== false || strpos($content, '[位置') !== false) {
                $content = trim((string)preg_replace('/\s*\[(?:来源|位置)[:：][^\]]*\]/u', '', $content));
            }
            // 验证内容(标记剥离后可能为空,如整条说说只有一个标记)
            list($valid, $message) = validateSayContent($content);
            // 验证失败直接抛出错误
            if (!$valid) return $this->response->throwJson(['status' => 0, 'message' => $message]);
            // 当前时间
            $currentTime = time();
            // 生成 uuid(响应里要用)
            $uuid = generateUUID();
            // 处理附件关联(memos 传 resourceIdList,或兼容 attachmentIds):先取有效附件行
            $attachmentIds = $data['resourceIdList'] ?? $data['attachmentIds'] ?? [];
            // 有效附件行(id 有效且附件存在)
            $attachments = [];
            // 逐个校验
            foreach ((array)$attachmentIds as $attachmentId) {
                // 转整型
                $attachmentId = (int)$attachmentId;
                // 附件 id 大于 0 才查
                if ($attachmentId <= 0) continue;
                // 检查附件是否存在
                $exists = $this->db->fetchRow($this->db->select()->from($this->db->getPrefix() . 'says_attachment')->where('id = ?', $attachmentId)->limit(1));
                // 存在才收集
                if ($exists) $attachments[] = $exists;
            }
            // 来源取值优先级:JSON 显式参数 > 内容 [来源: xxx] 标记 > 留空走 UA 识别
            $customSource = isset($data['source']) && trim((string)$data['source']) !== '' ? trim((string)$data['source']) : $markerSource;
            // 位置取值优先级:JSON 显式参数 > 内容 [位置: xxx] 标记 > 置空
            $customPosition = isset($data['position']) && trim((string)$data['position']) !== '' ? trim((string)$data['position']) : $markerPosition;
            // 插入数据库
            $insertId = $this->db->query($this->db->insert($this->saysTable)->rows([
                'uuid' => $uuid,
                'user_id' => $userId,
                'content' => $content,
                'agent' => $agent, // 直接加在ua里,把来源
                'ip' => $this->request->getIp() ?? '0.0.0.0',
                'source' => $customSource ?? '', // 来源为空就空字符串,不走 UA 识别
                'position' => $customPosition !== '' ? $customPosition : null,
                'status' => (strtoupper($data['visibility'] ?? 'public') === 'PUBLIC') ? 1 : 0,
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ]));
            // 关联附件(写入关联 + 引用计数加一,附件行已校验过)
            foreach ($attachments as $att) {
                // 写入关联
                $this->db->query($this->db->insert($this->db->getPrefix() . 'says_attachment_meta')->rows([
                    'say_id' => $insertId,
                    'attachment_id' => (int)$att['id'],
                    'created_at' => time(),
                ]));
                // 引用计数加一
                $this->db->query($this->db->update($this->db->getPrefix() . 'says_attachment')
                    ->rows(['num' => (int)$att['num'] + 1])
                    ->where('id = ?', (int)$att['id']));
            }
            // 解析内容中的 #标签 并关联
            \TypechoPlugin\Says\Hashtag::relate((int)$insertId, \TypechoPlugin\Says\Hashtag::extract($content));
            // 组装 memos 兼容的附件实体列表(externalLink 给公开地址,扩展预览直接可用)
            $resources = [];
            foreach ($attachments as $att) {
                $resources[] = [
                    'id' => (int)$att['id'],
                    'name' => 'resources/' . (int)$att['id'],
                    'filename' => $att['name'],
                    'type' => $att['mime'],
                    'size' => (int)$att['size'],
                    'externalLink' => $att['url'],
                ];
            }
            // 返回数据:附 memo 实体,扩展发布成功后靠它渲染新卡片(否则显示空条)
            $this->response->throwJson([
                'status' => 1,
                'message' => 'ok',
                'memo' => [
                    'id' => (int)$insertId,
                    'name' => 'memos/' . (int)$insertId,
                    'uid' => $uuid,
                    'content' => $content,
                    'visibility' => (strtoupper($data['visibility'] ?? 'public') === 'PUBLIC') ? 'PUBLIC' : 'PRIVATE',
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                    'resources' => $resources,
                ],
            ]);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取认证令牌
     */
    private function getAuthToken():string {
        // 从 Header 中获取 Bearer Token
        $authorization = $this->request->getHeader('Authorization');
        // 返回 Token
        return $authorization ? str_replace('Bearer ', '', $authorization) : '';
    }

    /**
     * 解析当前请求对应的用户ID(多用户+多令牌)
     *
     * 从 Authorization 头取 Bearer Token,与插件设置中的令牌表比对。
     * 令牌表配置格式:每行一个,形如 用户ID|令牌(如 1|abc123...)
     * 令牌比对前会 trim,忽略首尾空白。
     *
     * @return int|null 匹配到的用户ID,未匹配返回 null
     */
    private function resolveCurrentUser(): ?int {
        // 获取令牌
        $token = $this->getAuthToken();
        // 空令牌直接失败
        if (!$token) return null;
        // 读取配置中的多行令牌表
        $lines = explode("\n", (string)$this->options->token);
        // 逐行比对
        foreach ($lines as $line) {
            // 去除首尾空白
            $line = trim($line);
            // 跳过空行
            if ($line === '') continue;
            // 拆分为 id|令牌
            $parts = explode('|', $line, 2);
            // 格式不完整则跳过
            if (count($parts) !== 2) continue;
            // 用户ID
            $uid = (int)trim($parts[0]);
            // 令牌
            $lineToken = trim($parts[1]);
            // 用户ID有效且令牌匹配(trim后比对)
            if ($uid > 0 && $lineToken === trim($token)) {
                return $uid;
            }
        }
        // 未匹配
        return null;
    }
}