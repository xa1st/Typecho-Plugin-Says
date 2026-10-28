<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Storage;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Http\Client;

/**
 * Backblaze B2 云存储实现
 *
 * 使用 B2 API v2:
 *   1. b2_authorize_account    认证,获取 apiUrl / downloadUrl / authorizationToken
 *   2. b2_get_upload_url       获取上传地址(为稳妥起见,每个文件上传前单独获取)
 *   3. b2_delete_file_version  删除文件(需 fileName + fileId)
 *
 * 公共逻辑(base64 落盘 / MIME / 尺寸 / MD5)见 Storage。
 * 缩图支持:doUpload 可接收缩图临时文件,随原图一并上传,
 * fileId/fileName 记入 remark 供删除;缩图上传失败不阻断原图。
 * 密钥来源:config.inc.php 中定义 BACKBLAZE_KEY_ID / BACKBLAZE_APPLICATION_KEY
 * 需要的config字段: bucketId / bucketName / path / domain(可选)
 */
class Backblaze extends Storage {
    /**
     * B2 认证端点(v2)
     */
    private const AUTH_URL = 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account';

    /**
     * 账号 Key ID
     *
     * @var string
     */
    private $keyId;

    /**
     * 应用密钥
     *
     * @var string
     */
    private $applicationKey;

    /**
     * 存储桶 ID
     *
     * @var string
     */
    private $bucketId;

    /**
     * 存储桶名称
     *
     * @var string
     */
    private $bucketName;

    /**
     * 存储路径前缀,以 / 结尾
     *
     * @var string
     */
    private $path;

    /**
     * 自定义访问域名(留空使用 B2 默认)
     *
     * @var string
     */
    private $domain;

    /**
     * 认证信息缓存
     *
     * @var array|null
     */
    private $auth = null;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = []) {
        // 密钥从全局常量读取,不进数据库
        $this->keyId = defined('BACKBLAZE_KEY_ID') ? BACKBLAZE_KEY_ID : '';
        // 应用密钥从全局常量读取,不进数据库
        $this->applicationKey = defined('BACKBLAZE_APPLICATION_KEY') ? BACKBLAZE_APPLICATION_KEY : '';
        // 存储桶 ID
        $this->bucketId = $config['bucketId'] ?? '';
        // 存储桶名称
        $this->bucketName = $config['bucketName'] ?? '';
        // 存储路径前缀
        $this->path = rtrim($config['path'] ?? '', '/') . '/';
        // 自定义访问域名
        $this->domain = rtrim($config['domain'] ?? '', '/');

        // 校验必需配置,缺什么列什么
        $missing = [];
        // 密钥 ID
        if (empty($this->keyId)) $missing[] = 'BACKBLAZE_KEY_ID';
        // 应用密钥
        if (empty($this->applicationKey)) $missing[] = 'BACKBLAZE_APPLICATION_KEY';
        // 存储桶 ID
        if (empty($this->bucketId)) $missing[] = 'bucketId';
        // 存储桶名称
        if (empty($this->bucketName)) $missing[] = 'bucketName';
        // 有缺失则抛出异常
        if ($missing) throw new \Exception('Backblaze 缺少配置: ' . implode(', ', $missing));
    }

    /**
     * 真正上传到 B2
     *
     * @param string $filePath 本地文件路径(基类已就绪)
     * @param string $target 目标路径/文件名
     * @param array|null $thumb 缩图任务(物理存储模式由基类传入,无缩图为 null):
     *   ['path' => 缩图临时文件, 'target' => 相对目标路径]
     * @return array 附件表特有字段(storage/url/remark/thumb,thumb 为 null 时由基类回退原图)
     * @throws \Exception
     */
    protected function doUpload(string $filePath, string $target, ?array $thumb = null): array {
        // 原图桶内完整路径(带前缀)
        $remotePath = $this->path . $target;
        // 上传原图(失败直接抛出异常,整体上传失败)
        $result = $this->uploadOne($filePath, $remotePath);
        // remark:删除时需要的信息,原图的 fileId/fileName 必须记录
        $remark = [
            'fileId'          => $result['fileId'],
            'fileName'        => $remotePath,
            'bucketId'        => $this->bucketId,
            'bucket'          => $this->bucketName,
            'uploadTimestamp' => $result['uploadTimestamp'] ?? null,
            'contentSha1'     => $result['contentSha1'] ?? null,
        ];
        // 缩图 URL(未上传或上传失败时为 null,由基类回退原图)
        $thumbUrl = null;
        // 上传缩图
        if (!empty($thumb['path'])) {
            try {
                // 缩图桶内完整路径
                $thumbRemote = $this->path . $thumb['target'];
                // 上传
                $thumbResult = $this->uploadOne($thumb['path'], $thumbRemote);
                // 记录删除所需信息
                $remark['thumbFileId'] = $thumbResult['fileId'];
                $remark['thumbFileName'] = $thumbRemote;
                // 生成访问地址
                $thumbUrl = $this->getUrl($thumb['target']);
            } catch (\Throwable $e) {
                // 缩图上传失败不阻断主流程,前端回退原图
            }
        }
        // 返回存储特有字段(公共字段 name/size/mime/md5 由基类填充)
        return [
            // 存储类型
            'storage' => 'backblaze',
            // 文件 URL
            'url'     => $this->getUrl($target),
            // 缩图 URL
            'thumb'   => $thumbUrl,
            // B2 各文件的 fileId/fileName 全放 remark,删除时使用
            'remark'  => json_encode($remark, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 删除 B2 中的文件
     *
     * 从附件行的 remark 中取 fileId + fileName 删除原图(必须成功),
     * 缩图尽力而为,失败不阻断;老数据没有缩图记录时自动跳过。
     *
     * @param array $attachment 附件表完整行(remark 中包含各文件的 fileId/fileName)
     * @return bool 是否删除成功
     */
    public function delete(array $attachment): bool {
        try {
            // 从 remark 中提取 B2 保存的信息
            $remark = isset($attachment['remark']) ? json_decode($attachment['remark'], true) : [];
            // 缺少必要信息则失败
            if (!is_array($remark)) $remark = [];
            // 提取原图的 fileId 和 fileName
            $fileId = $remark['fileId'] ?? '';
            // 文件名
            $fileName = $remark['fileName'] ?? '';
            // 缺少必要信息则失败
            if ($fileId === '' || $fileName === '') return false;
            // 删除原图(必须成功)
            if (!$this->deleteFile($fileId, $fileName)) return false;
            // 缩图尽力删除,失败不阻断(老数据没有缩图记录则跳过)
            if (!empty($remark['thumbFileId'])) {
                $this->deleteFile((string)$remark['thumbFileId'], (string)$remark['thumbFileName']);
            }
            // 成功
            return true;
        } catch (\Exception $e) {
            // 删除失败,返回 false 不抛异常
            return false;
        }
    }

    /**
     * 上传单个文件到 B2
     *
     * @param string $filePath 本地文件路径
     * @param string $remotePath 桶内完整路径(带前缀)
     * @return array B2 响应(含 fileId/fileName/uploadTimestamp 等)
     * @throws \Exception
     */
    private function uploadOne(string $filePath, string $remotePath): array {
        // 读取文件内容
        $data = file_get_contents($filePath);
        // 读取失败
        if ($data === false) throw new \Exception('无法读取文件内容: ' . $filePath);
        // 认证 + 获取上传地址(为稳妥起见,每个文件单独获取上传地址)
        $auth = $this->authorize();
        $uploadUrl = $this->getUploadUrl($auth);
        // 开始上传文件
        try {
            // 初始化 HTTP 客户端
            $client = Client::get();
            // 未启用 cURL
            if (!$client) throw new \Exception('请启用 cURL 扩展');
            // 发起请求
            $client->setHeader('Authorization', $uploadUrl['authorizationToken'])
                ->setHeader('X-Bz-File-Name', rawurlencode($remotePath))
                ->setHeader('X-Bz-Content-Sha1', sha1($data))
                ->setHeader('X-Bz-Info-Author', 'Says')
                ->setTimeout(30)
                ->setData($data)
                ->setMethod(Client::METHOD_POST)
                ->send($uploadUrl['uploadUrl']);
            // 上传失败，直接抛出异常
            if ($client->getResponseStatus() != 200) throw new \Exception('B2 上传失败,HTTP ' . $client->getResponseStatus() . ': ' . $client->getResponseBody());
            // 获取响应内容
            $result = json_decode($client->getResponseBody(), true);
        } catch (\Exception $e) {
            throw new \Exception('B2 上传异常: ' . $e->getMessage());
        }
        // 上传失败
        if (empty($result['fileId'])) throw new \Exception('B2 上传失败: 未获取到 fileId');
        // 返回 B2 响应
        return $result;
    }

    /**
     * 删除 B2 中的单个文件版本
     *
     * @param string $fileId 文件版本 id
     * @param string $fileName 文件名
     * @return bool 是否删除成功
     */
    private function deleteFile(string $fileId, string $fileName): bool {
        try {
            // 认证
            $auth = $this->authorize();
            // 使用Typecho HTTP 客户端
            $client = Client::get();
            // 未启用 cURL
            if (!$client) throw new \Exception('请启用 cURL 扩展');
            // 删除文件
            $client->setHeader('Authorization', $auth['authorizationToken'])
                ->setJson(['fileName' => $fileName, 'fileId' => $fileId])
                ->setTimeout(10)
                ->setMethod(Client::METHOD_POST)
                ->send($auth['apiUrl'] . '/b2api/v2/b2_delete_file_version');
            // 返回删除结果
            return $client->getResponseStatus() == 200;
        } catch (\Exception $e) {
            // 删除失败,返回 false 不抛异常
            return false;
        }
    }

    /**
     * 获取文件公开访问 URL
     *
     * @param string $path 存储路径(不带前缀)
     * @return string
     */
    public function getUrl(string $path): string {
        // 完整存储路径(带前缀)
        $path = ltrim($path, '/');
        // 是否使用自定义域名
        return $this->domain !== '' ? ($this->domain . '/' . $this->path . $path) : ('https://f002.backblazeb2.com/file/' . $this->bucketName . '/' . $this->path . $path);
    }

    /**
     * B2 账号认证,返回 apiUrl / downloadUrl / authorizationToken 等
     *
     * @return array
     * @throws \Exception
     */
    private function authorize(): array  {
        // 已授权直接返回
        if ($this->auth !== null) return $this->auth;
        // 开始认证
        try {
            // 初始化HTTP 客户端
            $client = Client::get();
            // 未启用 cURL
            if (!$client) throw new \Exception('请启用 cURL 扩展');
            // 发起认证请求
            $client->setHeader('Authorization', 'Basic ' . base64_encode($this->keyId . ':' . $this->applicationKey))
                ->setTimeout(10)
                ->setMethod(Client::METHOD_GET)
                ->send(self::AUTH_URL);
            // 认证失败,抛出异常
            if ($client->getResponseStatus() != 200) throw new \Exception($client->getResponseBody());
            // 获取认证结果
            $this->auth = json_decode($client->getResponseBody(), true);
        } catch (\Exception $e) {
            // 认证失败,抛出异常
            throw new \Exception('B2 认证失败: ' . $e->getMessage());
        }
        // 返回认证结果
        return $this->auth;
    }

    /**
     * 获取上传地址
     *
     * @param array $auth 认证信息
     * @return array 包含 uploadUrl 与 authorizationToken
     * @throws \Exception
     */
    private function getUploadUrl(array $auth): array {
        try {
            // 初始化HTTP 客户端
            $client = Client::get();
            // 未启用 cURL
            if (!$client) throw new \Exception('请启用 cURL 扩展');
            // 获取上传地址
            $client->setHeader('Authorization', $auth['authorizationToken'])
                ->setJson(['bucketId' => $this->bucketId])
                ->setTimeout(10)
                ->setMethod(Client::METHOD_POST)
                ->send($auth['apiUrl'] . '/b2api/v2/b2_get_upload_url');
            // 获取上传地址失败,抛出异常
            if ($client->getResponseStatus() != 200) throw new \Exception($client->getResponseBody());
            // 获取上传地址成功
            $result = json_decode($client->getResponseBody(), true);
        } catch (\Exception $e) {
            throw new \Exception('B2 获取上传地址失败: ' . $e->getMessage());
        }
        // 获取了但是缺少必要字段,抛出异常
        if (empty($result['uploadUrl']) || empty($result['authorizationToken']))  throw new \Exception('B2 获取上传地址失败: 响应缺少必要字段');
        // 返回上传地址
        return $result;
    }
}
