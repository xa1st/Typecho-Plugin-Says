<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Storage;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Http\Client;

/**
 * 又拍云云存储实现
 *
 * 使用 REST API:
 *   PUT    /<bucket>/<path>   上传文件
 *   DELETE /<bucket>/<path>   删除文件
 *
 * 认证方式:Authorization: Upyun <operator>:<md5(password)>
 * 公共逻辑(base64 落盘 / MIME / 尺寸 / MD5)见 Storage 基类。
 * 缩图为参数模式:不生成本地缩图文件,直接在访问 URL 上拼接又拍云图片处理参数,
 * 由又拍云按需生成并边缘缓存(前提:空间已开通图片处理功能,参数不对或未开通会 404)。
 * 删除时无缩图文件需要处理。
 * 密钥来源:config.inc.php 中定义 SAYS_UPYUN_OPERATOR / SAYS_UPYUN_PASSWORD
 */
class Upyun extends Storage
{
    /**
     * 又拍云默认 API 端点
     */
    private const DEFAULT_ENDPOINT = 'https://v0.api.upyun.com';

    /**
     * 小图处理参数:等比限入 200 见方(前端按 100px 显示,高分屏按 2x 取图)
     */
    private const THUMB_PARAMS = '!/fw/200/fh/200';

    /**
     * 大图处理参数:宽压到 1920
     */
    private const LARGE_PARAMS = '!/fw/1920';

    /**
     * 空间名称
     *
     * @var string
     */
    private $bucket;

    /**
     * 操作员名称
     *
     * @var string
     */
    private $operator;

    /**
     * 操作员密码
     *
     * @var string
     */
    private $password;

    /**
     * API 端点(留空使用默认)
     *
     * @var string
     */
    private $endpoint;

    /**
     * 自定义访问域名(留空使用空间默认域名)
     *
     * @var string
     */
    private $customUrl;

    /**
     * 构造函数
     *
     * @param array $config 配置数组
     */
    public function __construct(array $config = []) {
        // 密钥从全局常量读取,不进数据库
        $this->operator = defined('SAYS_UPYUN_OPERATOR') ? SAYS_UPYUN_OPERATOR : '';
        // 密码从全局常量读取,不进数据库
        $this->password = defined('SAYS_UPYUN_PASSWORD') ? SAYS_UPYUN_PASSWORD : '';
        // 空间名称
        $this->bucket = $config['bucket'] ?? '';
        // API 端点,默认使用官方
        $this->endpoint = rtrim($config['endpoint'] ?? self::DEFAULT_ENDPOINT, '/');
        // 自定义访问域名(留空使用空间默认域名)
        $this->customUrl = rtrim($config['url'] ?? '', '/');

        // 校验必需配置,缺什么列什么
        $missing = [];
        // 操作员
        if (empty($this->operator)) $missing[] = 'SAYS_UPYUN_OPERATOR';
        // 密码
        if (empty($this->password)) $missing[] = 'SAYS_UPYUN_PASSWORD';
        // 空间名称
        if (empty($this->bucket)) $missing[] = 'bucket';
        // 有缺失则抛出异常
        if ($missing)  throw new \Exception('又拍云缺少配置: ' . implode(', ', $missing));
    }

    /**
     * 缩图模式:参数模式(URL 拼处理参数,由又拍云按需生成,无需本地缩图文件)
     *
     * @return string 'param'
     */
    public function thumbMode(): string {
        // 参数模式
        return 'param';
    }

    /**
     * 真正上传到又拍云
     *
     * 缩图为参数模式:只传原图,缩图/大图在访问 URL 上拼图片处理参数。
     * GIF 动图与非图片不拼参数(保动画完整/处理无意义),直接用原图地址。
     *
     * @param string $filePath 本地文件路径(基类已就绪)
     * @param string $target 目标路径/文件名
     * @param array|null $thumbs 参数模式忽略该参数(与物理存储保持签名一致)
     * @return array 附件表特有字段(storage/url/remark/thumb/large)
     * @throws \Exception
     */
    protected function doUpload(string $filePath, string $target, ?array $thumbs = null): array {
        // 读取文件内容
        $data = file_get_contents($filePath);
        // 读取失败
        if ($data === false) throw new \Exception('无法读取文件内容: ' . $filePath);
        // 上传地址
        $url = $this->endpoint . '/' . $this->bucket . '/' . $target;
        // 发起 PUT 请求上传
        $code = $this->request('PUT', $url, $data);
        // 非 2xx 视为失败
        if ($code < 200 || $code >= 300) throw new \Exception('又拍云上传失败,HTTP ' . $code);
        // 原图访问地址
        $origin = $this->getUrl($target);
        // 缩图/大图 URL(默认与原图一致,下面按文件信息决定是否拼处理参数)
        $thumb = $origin;
        // 大图 URL
        $large = $origin;
        // 读取图片基础信息(宽高/MIME,非图片时返回 false)
        $info = @getimagesize($filePath);
        // 是图片且非 GIF 动图才拼参数
        if ($info !== false && ($info['mime'] ?? '') !== 'image/gif') {
            // 小图:等比限入 200 见方
            $thumb = $origin . self::THUMB_PARAMS;
            // 大图:宽超 1920 才拼参数(参数按存储像素处理,无需考虑 EXIF 方向)
            if ((int)$info[0] > self::LARGE_WIDTH) {
                $large = $origin . self::LARGE_PARAMS;
            }
        }
        // 返回存储特有字段(公共字段 name/size/mime/md5 由基类填充)
        return [
            'storage' => 'upyun',
            'url'     => $origin,
            'thumb'   => $thumb,
            'large'   => $large,
            // 删除时用:空间内文件路径(缩图为参数模式,无物理文件,无需记录)
            'remark'  => json_encode(['bucket'   => $this->bucket, 'filePath' => $target], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 删除又拍云中的文件
     *
     * 缩图为参数模式(无物理文件),只需删除原图。
     *
     * @param array $attachment 附件表完整行(remark 中包含 filePath)
     * @return bool 是否删除成功
     */
    public function delete(array $attachment): bool {
        try {
            // 从 remark 中提取路径
            $remark = isset($attachment['remark']) ? json_decode($attachment['remark'], true) : [];
            if (!is_array($remark)) $remark = [];
            $filePath = $remark['filePath'] ?? '';
            // 路径为空则失败
            if ($filePath === '') return false;
            // 删除地址
            $url = $this->endpoint . '/' . $this->bucket . '/' . $filePath;
            // 发起 DELETE 请求
            $code = $this->request('DELETE', $url, null);
            // 返回是否成功
            return $code >= 200 && $code < 300;
        } catch (\Exception $e) {
            // 删除失败,返回 false
            return false;
        }
    }

    /**
     * 获取文件公开访问 URL
     *
     * @param string $path 存储路径
     * @return string
     */
    public function getUrl(string $path): string {
        // 自定义域名
        $base = $this->customUrl;
        // 未配置使用空间默认域名
        if ($base === '') $base = 'https://' . $this->bucket . '.b0.upaiyun.com';
        // 拼接完整 URL
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * 发起 HTTP 请求
     *
     * @param string $method PUT / DELETE
     * @param string $url
     * @param string|null $body 请求体
     * @return int HTTP 状态码
     * @throws \Exception
     */
    private function request(string $method, string $url, $body): int {
        // Typecho HTTP 客户端
        $client = Client::get();
        // 未启用 cURL
        if (!$client) throw new \Exception('请启用 cURL 扩展');

        try {
            // 设置请求方法和数据
            $client->setMethod($method)
                ->setTimeout(30)
                ->setData($body)
                ->setHeader('Authorization', 'Upyun ' . $this->operator . ':' . md5($this->password))
                ->setHeader('Date', gmdate('D, d M Y H:i:s') . ' GMT');
            // 有请求体则设置 Content-Length
            if ($body !== null) $client->setHeader('Content-Length', (string)strlen($body));
            // 发送请求
            $client->send($url);
            // 返回状态码
            return $client->getResponseStatus();
        } catch (\Exception $e) {
            throw new \Exception('又拍云 HTTP 请求失败: ' . $e->getMessage());
        }
    }
}
