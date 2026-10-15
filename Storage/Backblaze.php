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
 *   2. b2_get_upload_url       获取上传地址(上传前获取)
 *   3. b2_delete_file_version  删除文件(需 fileName + fileId)
 *
 * 公共逻辑(base64 落盘 / MIME / 尺寸 / MD5)见 Storage。
 * 密钥来源:config.inc.php 中定义 SAYS_B2_KEY_ID / SAYS_B2_APPLICATION_KEY
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
        $this->keyId = defined('SAYS_B2_KEY_ID') ? SAYS_B2_KEY_ID : '';
        // 应用密钥从全局常量读取,不进数据库
        $this->applicationKey = defined('SAYS_B2_APPLICATION_KEY') ? SAYS_B2_APPLICATION_KEY : '';
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
        if (empty($this->keyId)) $missing[] = 'SAYS_B2_KEY_ID';
        // 应用密钥
        if (empty($this->applicationKey)) $missing[] = 'SAYS_B2_APPLICATION_KEY';
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
     * @return array 附件表特有字段(storage/url/remark)
     * @throws \Exception
     */
    protected function doUpload(string $filePath, string $target): array {
        // 完整存储路径(带前缀)
        $remotePath = $this->path . $target;
        // 读取文件内容
        $data = file_get_contents($filePath);
        // 读取失败
        if ($data === false) throw new \Exception('无法读取文件内容: ' . $filePath);
        // 认证 + 获取上传地址
        $auth = $this->authorize();
        // 获取上传地址
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
        // 返回存储特有字段(公共字段 name/size/mime/md5 由基类填充)
        return [
            // 存储类型
            'storage' => 'backblaze',
            // 文件 URL
            'url'     => $this->getUrl($target),
            // B2 删除需要 fileId + fileName,全放 remark
            'remark'  => json_encode([
                'fileId'          => $result['fileId'],
                'fileName'        => $remotePath,
                'bucketId'        => $this->bucketId,
                'bucket'          => $this->bucketName,
                'uploadTimestamp' => $result['uploadTimestamp'] ?? null,
                'contentSha1'     => $result['contentSha1'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 删除 B2 中的文件
     *
     * 从附件行的 remark 中取 fileId + fileName 直接删除,不再按文件名搜索
     *
     * @param array $attachment 附件表完整行(remark 中包含 fileId/fileName)
     * @return bool 是否删除成功
     */
    public function delete(array $attachment): bool {
        try {
            // 从 remark 中提取 B2 保存的信息
            $remark = isset($attachment['remark']) ? json_decode($attachment['remark'], true) : [];
            // 缺少必要信息则失败
            if (!is_array($remark)) $remark = [];
            // 提取 fileId 和 fileName
            $fileId = $remark['fileId'] ?? '';
            // 文件名
            $fileName = $remark['fileName'] ?? '';
            // 缺少必要信息则失败
            if ($fileId === '' || $fileName === '') return false;
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