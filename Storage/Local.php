<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Storage;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Utils\Helper;

/**
 * 本地存储实现
 *
 * 文件固定存放在站点 usr/uploads 目录下(与 Typecho 原生附件目录一致),
 * 其下的子目录按 storagePath(date 格式)划分,如 Y/md → usr/uploads/2025/1014/uuid.png
 * 缩图支持:doUpload 可接收缩图临时文件,随原图一并存放(缩图失败不阻断原图);
 * 删除时缩图路径由原图路径确定性推导(扩展名前插 .thumb),无需额外记录。
 * 公共逻辑(base64 落盘 / MIME / 尺寸 / MD5)见 Storage 基类。
 */
class Local extends Storage
{
    /**
     * 存储根目录,相对站点根目录(固定)
     *
     * @var string
     */
    private const ROOT_PATH = 'usr/uploads';

    /**
     * 公开访问 URL 前缀(留空则使用站点 siteUrl)
     *
     * @var string
     */
    private $url;

    /**
     * 构造函数
     *
     * @param array $config 配置数组(url 可选)
     */
    public function __construct(array $config = []) {
        // 公开访问 URL 前缀(留空使用站点 siteUrl)
        $this->url = rtrim($config['url'] ?? '', '/');
    }

    /**
     * 真正上传到本地
     *
     * @param string $filePath 本地文件路径(基类已就绪)
     * @param string $target 目标路径/文件名
     * @param array|null $thumb 缩图任务(物理存储模式由基类传入,无缩图为 null):
     *   ['path' => 缩图临时文件, 'target' => 相对目标路径]
     * @return array 附件表特有字段(storage/url/remark/thumb,thumb 为 null 时由基类回退原图)
     * @throws \Exception
     */
    protected function doUpload(string $filePath, string $target, ?array $thumb = null): array {
        // 拼接绝对路径: 站点根目录 / 存储根目录 / 目标路径
        $absPath = __TYPECHO_ROOT_DIR__ . '/' . self::ROOT_PATH . '/' . $target;
        // 创建目录
        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new \Exception('创建目录失败: ' . $dir);
        // 复制文件到目标
        if (!@copy($filePath, $absPath)) throw new \Exception('复制文件失败: ' . $filePath . ' -> ' . $absPath);
        // 缩图 URL(未生成或存放失败时为 null,由基类回退原图)
        $thumbUrl = null;
        // 存放缩图(与原图同目录,复制失败不阻断原图)
        if (!empty($thumb['path'])) {
            // 缩图绝对路径(目录与原图一致)
            $thumbAbs = __TYPECHO_ROOT_DIR__ . '/' . self::ROOT_PATH . '/' . $thumb['target'];
            // 复制成功才生成地址
            if (@copy($thumb['path'], $thumbAbs)) $thumbUrl = $this->getUrl($thumb['target']);
        }
        // 返回存储特有字段(公共字段 name/size/mime/md5 由基类填充)
        return [
            'storage' => 'local',
            'url'     => $this->getUrl($target),
            'thumb'   => $thumbUrl,
            // 删除时用:相对站点根目录的完整路径(缩图路径由该路径推导,无需单独记录)
            'remark'  => json_encode(['path' => self::ROOT_PATH . '/' . $target,], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * 删除本地文件
     *
     * 从 remark 中提取原图相对路径,必须删除成功;
     * 缩图路径由原图路径确定性推导(继承原图路径的安全校验),尽力而为,失败不阻断。
     *
     * @param array $attachment 附件表完整行(remark 中包含 path)
     * @return bool 是否删除成功
     */
    public function delete(array $attachment): bool {
        // 从 remark 中提取本地保存的相对路径
        $remark = isset($attachment['remark']) ? json_decode($attachment['remark'], true) : [];
        // 如果路径为空或不在存储目录内则返回失败
        if (!is_array($remark)) $remark = [];
        // 相对路径
        $path = $remark['path'] ?? '';
        // 确保位于存储根目录内,防止误删其他文件
        if ($path === '' || strncmp($path, self::ROOT_PATH . '/', strlen(self::ROOT_PATH) + 1) !== 0) return false;
        // 绝对路径
        $absPath = __TYPECHO_ROOT_DIR__ . '/' . $path;
        // 不是文件则返回失败
        if (!is_file($absPath)) return false;
        // 删除文件(必须成功)
        if (!@unlink($absPath)) return false;
        // 缩图尽力删除,失败不阻断(老数据可能没有缩图文件)
        // 路径由原图路径推导(扩展名前插 .thumb,仍在存储根目录内)
        $thumbPath = \TypechoPlugin\Says\Thumbnail\Thumbnail::deriveTarget($path);
        // 绝对路径
        $thumbAbs = __TYPECHO_ROOT_DIR__ . '/' . $thumbPath;
        // 是文件才删除
        if (is_file($thumbAbs)) @unlink($thumbAbs);
        // 返回成功
        return true;
    }

    /**
     * 获取文件公开访问 URL
     *
     * @param string $path 文件相对存储目录的路径
     * @return string
     */
    public function getUrl(string $path): string {
        // 拼接 URL: 公开访问 URL 前缀 / 存储根目录 / 文件路径
        $baseUrl = $this->url ?: Helper::options()->siteUrl;
        // 确保 URL 以 / 结尾
        return rtrim($baseUrl, '/') . '/' . self::ROOT_PATH . '/' . ltrim($path, '/');
    }
}