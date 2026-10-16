<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Storage;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Common;

/**
 * 存储抽象基类(模板方法)
 *
 * 统一处理所有存储共用的逻辑:
 *   - 目标路径规范化,防止目录穿越
 *   - base64 数据统一落盘为临时文件(与普通文件路径统一,后续全走文件路径)
 *   - MIME 检测(Typecho 原生 finfo,不做扩展名猜谜)
 *   - 文件大小 / MD5 统计(供附件表 md5 去重 / num 引用计数)
 *   - 临时文件清理(try/finally,异常也不残留)
 *
 * 子类只需实现:
 *   - doUpload():真正执行上传,返回存储特有字段(storage/url/remark)
 *   - delete():删除存储中的文件
 *
 * 附件表公共字段(name/size/mime/md5)由基类自动填充,
 * 各存储特有字段(如 B2 的 fileId)由子类放进 remark。
 */
abstract class Storage {
    /**
     * 上传文件到存储(模板方法)
     *
     * @param string $source 本地文件路径,或 base64 数据($isbase64 为 true 时)
     * @param string $target 目标路径/文件名,如 2025/10/xxx.jpg
     * @param bool $isbase64 是否为 base64 编码的数据
     * @return array 附件表行数据
     * @throws \Exception 上传失败抛出异常
     */
    public function upload(string $source, string $target, $isbase64 = false): array {
        // 规范化目标路径,防止目录穿越
        $target = $this->normalizePath($target);
        // 统一源文件为本地文件路径
        $filePath = $source;
        // 是否为临时文件
        $isTmp = false;
        // base64 数据:解码后落盘为临时文件
        if ($isbase64) {
            // base64 数据:解码后落盘为临时文件
            $data = base64_decode($source, true);
            // 解码失败,直接抛出异常
            if ($data === false) throw new \Exception('base64 解码失败');
            // base64 数据:解码后落盘为临时文件
            $filePath = tempnam(sys_get_temp_dir(), 'says_');
            // 创建临时文件失败,直接抛出异常
            if ($filePath === false) throw new \Exception('创建临时文件失败');
            // 写入临时文件失败,直接抛出异常
            if (file_put_contents($filePath, $data) === false) {
                @unlink($filePath);
                throw new \Exception('写入临时文件失败');
            }
            // 标记为临时文件
            $isTmp = true;
        } elseif (!file_exists($source) || !is_readable($source)) {
            // 普通文件路径:检查可读
            throw new \Exception('文件不存在或不可读: ' . $source);
        }

        try {
            // 子类执行真正的上传,返回存储特有字段
            $result = $this->doUpload($filePath, $target);
            // 组装附件表公共字段 + 子类特有字段
            return array_merge([
                'name' => basename($target),
                'size' => $this->detectSize($filePath),
                'mime' => $this->detectMime($filePath),
                'md5'  => $this->detectMd5($filePath),
            ], $result);
        } finally {
            // 清理临时文件,异常也不残留
            if ($isTmp && is_file($filePath))  @unlink($filePath);
        }
    }

    /**
     * 真正执行上传(子类实现)
     *
     * 返回附件表行数据中属于各存储的字段,例如:
     *   ['storage' => 'backblaze', 'url' => '...', 'remark' => '{...}']
     * 公共字段(name/size/mime/md5)由基类自动填充,无需返回。
     *
     * @param string $filePath 已就绪的本地文件路径(可能是 base64 落盘的临时文件)
     * @param string $target 目标路径/文件名(已规范化)
     * @return array 存储特有字段
     * @throws \Exception 上传失败抛出异常
     */
    abstract protected function doUpload(string $filePath, string $target): array;

    /**
     * 删除存储中的文件(子类实现)
     *
     * @param array $attachment 附件表完整行(含 id/url/remark/md5 等),各存储各取所需
     * @return bool 是否删除成功
     */
    abstract public function delete(array $attachment): bool;

    /**
     * 规范化目标路径,防止目录穿越
     *
     * @param string $path
     * @return string
     * @throws \Exception 路径非法时抛出异常
     */
    protected function normalizePath(string $path): string {
        // 去掉开头的 / 和 \
        $path = ltrim(str_replace('\\', '/', $path), '/');
        // 禁止目录穿越
        if (strpos($path, '..') !== false)  throw new \Exception('非法的目标路径: ' . $path);
        // 返回
        return $path;
    }

    /**
     * 检测文件 MIME 类型
     *
     * 复用 Typecho 原生 Common::mimeContentType(内部优先 finfo/mime_content_type,
     * 仅 finfo 不可用时才退化为扩展名映射)
     *
     * @param string $filePath
     * @return string
     */
    protected function detectMime(string $filePath): string {
        // 复用 Typecho 原生 Common::mimeContentType
        return Common::mimeContentType($filePath);
    }

    /**
     * 检测文件大小(字节)
     *
     * @param string $filePath
     * @return int
     */
    protected function detectSize(string $filePath): int {
        // 获取文件大小
        $size = @filesize($filePath);
        // 失败时返回 0
        return $size === false ? 0 : (int)$size;
    }

    /**
     * 计算文件 MD5(供附件去重)
     *
     * @param string $filePath
     * @return string 32 位小写 MD5,失败返回空字符串
     */
    protected function detectMd5(string $filePath): string {
        // 计算文件 MD5
        $md5 = @md5_file($filePath);
        // 失败时返回空字符串
        return $md5 === false ? '' : $md5;
    }
}