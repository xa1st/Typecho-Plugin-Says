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
     * 获取所有 Memo (GET /api/v1/memo)
     * 参数类型 limit=8&page=1
     */
    public function getMemos() {
        // 每页条数
        $limit = $this->request->filter('int')->get('limit', 8);
        // 默认第 1 页
        $page = $this->request->filter('int')->get('page', 1);   
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
                        'name' => $att['name'],
                        'size' => (int)$att['size'],
                        'mime' => $att['mime'],
                    ];
                }
                // 组装数据
                $data[] = [
                    'uid' => $memo['uuid'],
                    'content' => $memo['content'],
                    'created_at' => intval($memo['created_at']),
                    'updated_at' => intval($memo['updated_at']),
                    'from' => !empty($memo['source']) ? trim($memo['source']) : getPlatform($memo['agent']),
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
     * 检查认证状态 (POST /memos/api/v1/auth/status)
     * 参数类型 authorization: Bearer + token
     */
    public function authStatus() {
        // 如果插件停用，直接返回404
        if (!$this->options->isOpen) throw new Exception(_t('接口不存在'), 404);
        try {
            // 获取 token 并反查对应用户(多用户+多令牌)
            $userId = $this->resolveCurrentUser();
            // 鉴权失败
            if (!$userId) $this->response->throwJson(['status' => 0, 'message' => 'Unauthorized']);
            // 返回用户信息
            $user = $this->db->fetchRow($this->db->select('screenName', 'mail')->from($this->db->getPrefix() . 'users')->where('uid = ?', $userId));
            // 用户不存在则抛出错误
            if (!$user) $this->response->throwJson(['status' => 0, 'message' => _t('指定的说说用户不存在.')]);
            // 生成头像URL (使用Gravatar)
            $avatarUrl = __TYPECHO_GRAVATAR_PREFIX__ . md5(strtolower(trim($user['mail']))) . '?s=64&d=identicon';
            // 返回数据
            $this->response->throwJson(['id' => $userId, 'nickname' => $user['screenName'], 'avatar' => $avatarUrl]);
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
            // 验证内容
            list($valid, $message) = validateSayContent($content);
            // 验证失败直接抛出错误
            if (!$valid) return $this->response->throwJson(['status' => 0, 'message' => $message]);
            // 当前时间
            $currentTime = time();
            // 来源:API可传 source,未传则按UA识别一次并落库(与后台一致)
            $customSource = isset($data['source']) ? trim((string)$data['source']) : '';
            // 位置:API可传 position,未传则置空
            $customPosition = isset($data['position']) ? trim((string)$data['position']) : '';
            // 插入数据库
            $insertId = $this->db->query($this->db->insert($this->saysTable)->rows([
                'uuid' => generateUUID(),
                'user_id' => $userId,
                'content' => $content,
                'agent' => $agent, // 直接加在ua里,把来源
                'ip' => $this->request->getIp() ?? '0.0.0.0',
                'source' => $customSource !== '' ? $customSource : getPlatform($agent),
                'position' => $customPosition !== '' ? $customPosition : null,
                'status' => (strtoupper($data['visibility'] ?? 'public') === 'PUBLIC') ? 1 : 0,
                'created_at' => $currentTime,
                'updated_at' => $currentTime
            ]));
            // 处理附件关联(memos 传 resourceIdList,或兼容 attachmentIds)
            $attachmentIds = $data['resourceIdList'] ?? $data['attachmentIds'] ?? [];
            // 有附件则批量关联
            if (!empty($attachmentIds)) {
                // 数据库实例
                $db = Db::get();
                // 表前缀
                $prefix = $db->getPrefix();
                // 逐个关联附件
                foreach ($attachmentIds as $attachmentId) {
                    $attachmentId = (int)$attachmentId;
                    // 附件 id 大于 0 才关联
                    if ($attachmentId > 0) {
                        // 检查附件是否存在
                        $exists = $db->fetchRow($db->select()->from($prefix . 'says_attachment')->where('id = ?', $attachmentId)->limit(1));
                        // 存在才关联
                        if ($exists) {
                            // 写入关联
                            $db->query($db->insert($prefix . 'says_attachment_meta')->rows([
                                'say_id' => $insertId,
                                'attachment_id' => $attachmentId,
                                'created_at' => time(),
                            ]));
                            // 引用计数加一
                            $db->query($db->update($prefix . 'says_attachment')
                                ->rows(['num' => (int)$exists['num'] + 1])
                                ->where('id = ?', $attachmentId));
                        }
                    }
                }
            }
            // 解析内容中的 #标签 并关联
            \TypechoPlugin\Says\Hashtag::relate((int)$insertId, \TypechoPlugin\Says\Hashtag::extract($content));
            // 直接返回数据
            $this->response->throwJson(['status' => 1, 'message' => 'ok']);
        } catch (\Exception $e) {
            // 抛出错误
            $this->response->throwJson(['status' => 0, 'message' => 'Internal server error: ' . $e->getMessage()]);
        }
    }

    /**
     * 上传附件 (POST /memos/api/v1/attachment)
     * 兼容 memos 上传接口
     * 参数类型 {"content":"文件base64编码","filename":"文件名","type":"文件MIME如image/jpeg"}
     * 返回 memos 兼容的附件信息
     */
    public function uploadAttachment() {
        // 如果插件停用,直接返回404
        if (!$this->options->isOpen) throw new Exception(_t('接口不存在'), 404);
        try {
            // 获取 token 并反查对应用户(多用户+多令牌)
            $userId = $this->resolveCurrentUser();
            // 鉴权失败
            if (!$userId) $this->response->throwJson(['status' => 0, 'message' => 'Unauthorized']);
            // 获取 JSON 请求数据
            $data = json_decode(file_get_contents('php://input'), true);
            // 验证 JSON 解析是否成功
            if (!is_array($data)) return $this->response->throwJson(['status' => 0, 'message' => 'Invalid JSON data']);
            // 获取 base64 内容
            $content = isset($data['content']) ? trim($data['content']) : '';
            // 获取文件名
            $filename = isset($data['filename']) ? basename(trim($data['filename'])) : '';
            // 获取 MIME 类型
            $type = isset($data['type']) ? trim($data['type']) : '';
            // 验证内容不为空
            if (empty($content)) return $this->response->throwJson(['status' => 0, 'message' => '内容不能为空']);
            // 验证文件名
            if (empty($filename)) return $this->response->throwJson(['status' => 0, 'message' => '文件名不能为空']);
            // 去掉 base64 前缀(data:image/png;base64,xxx)
            if (strpos($content, 'base64,') !== false) {
                // 截取逗号之后的部分
                $content = substr($content, strpos($content, 'base64,') + 7);
            }
            // 校验大小:解码前估算(base64 长度 * 3/4 ≈ 解码后字节数)
            // 最大大小配置,单位 M,留空表示不限制
            $maxMb = trim($this->options->maxFileSize ?: '');
            // 配置了才校验
            if ($maxMb !== '') {
                // 换算为字节
                $maxBytes = (int)$maxMb * 1024 * 1024;
                // 估算解码后大小
                $estBytes = (int)(strlen($content) * 3 / 4);
                // 超出上限则拒绝
                if ($estBytes > $maxBytes) {
                    return $this->response->throwJson(['status' => 0, 'message' => '文件大小超过限制: ' . $maxMb . 'M']);
                }
            }
            // 校验文件类型(允许的类型列表)
            $allowedTypes = getAllowedFileTypes();
            // 取文件名扩展名
            $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            // 文件名无扩展名时根据 MIME 推断
            if (empty($fileExt)) {
                // MIME → 扩展名 映射
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/bmp' => 'bmp'];
                // 按 MIME 反查
                $fileExt = $extMap[$type] ?? '';
            }
            // 类型不在白名单则拒绝
            if (!in_array($fileExt, $allowedTypes)) {
                return $this->response->throwJson(['status' => 0, 'message' => '不允许的文件类型: ' . $fileExt]);
            }
            // 生成存储目标路径(按配置的存储路径 + 重命名规则)
            $target = generateTargetPath($filename, $fileExt);
            // 上传附件(调用存储上传并登记数据库)
            $attachment = \TypechoPlugin\Says\Attachment::upload($content, $target, true);
            // 返回 memos 兼容的附件信息
            $this->response->throwJson([
                'id' => $attachment['id'],
                'name' => 'attachments/' . $target,
                'filename' => $filename,
                'size' => $attachment['size'],
                'type' => $type ?: $attachment['mime'],
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