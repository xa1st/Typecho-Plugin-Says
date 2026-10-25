<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Storage;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Common;
use TypechoPlugin\Says\Thumbnail\Thumbnail;

/**
 * 存储抽象基类(模板方法)
 *
 * 统一处理所有存储共用的逻辑:
 *   - 目标路径规范化,防止目录穿越
 *   - base64 数据统一落盘为临时文件(与普通文件路径统一,后续全走文件路径)
 *   - MIME 检测(Typecho 原生 finfo,不做扩展名猜谜)
 *   - 文件大小 / MD5 统计(供附件表 md5 去重 / num 引用计数)
 *   - 缩图生成:物理模式存储(默认)上传前调用 Thumbnail 生成小图/大图,随原图一并上传;
 *     参数模式存储(覆写 thumbMode() 返回 'param',如又拍云)跳过生成,由存储自行拼处理参数 URL
 *   - 临时文件清理(try/finally,异常也不残留)
 *
 * 子类只需实现:
 *   - doUpload():真正执行上传,返回存储特有字段(storage/url/remark/thumb/large)
 *   - delete():删除存储中的文件
 *   - 参数模式存储额外覆写 thumbMode() 返回 'param'
 *
 * 附件表公共字段(name/size/mime/md5)由基类自动填充,
 * thumb/large 为空时基类自动回退原图 url,保证字段永远有值,
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

        // 缩图临时文件列表(finally 统一清理)
        $thumbTempFiles = [];
        try {
            // 生成缩图/大图(仅物理模式;参数模式如又拍云不生成本地文件,由存储自行拼处理参数 URL)
            $thumbs = null;
            if ($this->thumbMode() === 'physical') {
                try {
                    $thumbs = $this->buildThumbs($filePath, $target, $thumbTempFiles);
                } catch (\Throwable $e) {
                    // 缩图生成异常不阻断上传,直接回退原图
                    $thumbs = null;
                }
            }
            // 子类执行真正的上传,返回存储特有字段
            $result = $this->doUpload($filePath, $target, $thumbs);
            // 组装附件表公共字段 + 子类特有字段
            $row = array_merge([
                'name' => basename($target),
                'size' => $this->detectSize($filePath),
                'mime' => $this->detectMime($filePath),
                'md5'  => $this->detectMd5($filePath),
            ], $result);
            // 缩图字段兜底:没有生成/上传成功的直接用原图地址,保证字段永远有值
            $row['thumb'] = !empty($row['thumb']) ? $row['thumb'] : $row['url'];
            $row['large'] = !empty($row['large']) ? $row['large'] : $row['url'];
            // 返回
            return $row;
        } finally {
            // 清理临时文件,异常也不残留
            if ($isTmp && is_file($filePath))  @unlink($filePath);
            // 清理缩图临时文件(上传已在 doUpload 内完成,临时文件不再需要)
            foreach ($thumbTempFiles as $tmp) {
                if (is_file($tmp)) @unlink($tmp);
            }
        }
    }

    /**
     * 缩图模式:物理模式(基类生成缩图文件并随原图一并上传)
     *
     * 参数模式的存储(如又拍云)覆写本方法返回 'param',基类将跳过本地缩图生成。
     *
     * @return string 'physical' 或 'param'
     */
    public function thumbMode(): string {
        // 物理模式
        return 'physical';
    }

    /**
     * 生成缩图/大图并组装 doUpload 的缩图任务
     *
     * @param string $filePath 已就绪的本地原图路径
     * @param string $target 已规范化的原图目标路径
     * @param array $thumbTempFiles 输出:生成的临时文件路径列表(供 finally 统一清理)
     * @return array|null doUpload 的 $thumbs 参数,不适合缩或生成失败时为 null
     */
    private function buildThumbs(string $filePath, string $target, array &$thumbTempFiles): ?array {
        // 生成缩图(不适合缩或小图生成失败时返回 null,整体回退原图)
        $generated = Thumbnail::create($filePath);
        // 无缩图
        if (empty($generated)) return null;
        // 组装缩图任务
        $thumbs = [];
        foreach (['thumb', 'large'] as $key) {
            // 该级未生成(如大图宽未超上限)则为 null,由存储层跳过
            if (empty($generated[$key])) {
                $thumbs[$key] = null;
                continue;
            }
            // 临时文件路径 + 目标路径(扩展名前插标记)
            $thumbs[$key] = [
                'path'   => $generated[$key]['path'],
                'target' => Thumbnail::deriveTarget($target, $key),
            ];
            // 登记临时文件,供 finally 清理
            $thumbTempFiles[] = $generated[$key]['path'];
        }
        // 返回
        return $thumbs;
    }

    /**
     * 真正执行上传(子类实现)
     *
     * 返回附件表行数据中属于各存储的字段,例如:
     *   ['storage' => 'backblaze', 'url' => '...', 'thumb' => '...', 'large' => '...', 'remark' => '{...}']
     * 公共字段(name/size/mime/md5)由基类自动填充,无需返回;
     * thumb/large 为 null 时基类自动回退原图 url。
     *
     * @param string $filePath 已就绪的本地文件路径(可能是 base64 落盘的临时文件)
     * @param string $target 目标路径/文件名(已规范化)
     * @param array|null $thumbs 缩图任务(仅物理模式传入,参数模式为 null):
     *   ['thumb' => ['path' => 缩图临时文件, 'target' => 相对目标路径] | null,
     *    'large' => 同上 | null]
     * @return array 存储特有字段
     * @throws \Exception 上传失败抛出异常
     */
    abstract protected function doUpload(string $filePath, string $target, ?array $thumbs = null): array;

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
