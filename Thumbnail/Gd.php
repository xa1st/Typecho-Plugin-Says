<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Thumbnail;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * GD 驱动(GD 几乎所有主机必装,作为兜底)
 *
 * 相比 Imagick 驱动的差异:
 *   - 重采样用 imagecopyresampled,画质略逊
 *   - EXIF 方向需手动读 exif 并旋转
 *   - 解码大图吃内存,渲染前临时上调 memory_limit,结束后还原
 *   - 不支持 avif 编码,由 supportedMimes 排除(命中时主类直接跳过,回退原图)
 */
class Gd extends Thumbnail
{
    /**
     * 本驱动支持的原图格式
     *
     * @return string[]
     */
    public function supportedMimes(): array
    {
        // jpeg/png/webp(gif 动图已在主类统一跳过;avif 编码环境要求苛刻,不在此支持)
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * 渲染一张缩图
     *
     * @param string $source 原图路径
     * @param int $dstWidth 目标宽
     * @param int $dstHeight 目标高
     * @param string $dest 输出文件路径(无扩展名,格式由 $mime 决定)
     * @param string $mime 原图 MIME(输出同格式)
     * @param int $quality 输出质量(jpeg/webp 有效,png 无损忽略)
     * @return bool 是否成功
     */
    public function render(string $source, int $dstWidth, int $dstHeight, string $dest, string $mime, int $quality): bool
    {
        // 临时上调内存上限(2000 万像素解码约需 80M,64M 主机必挂),结束后还原
        $oldLimit = $this->raiseMemory();
        try {
            // 解码原图
            $image = $this->decode($source, $mime);
            // 解码失败
            if ($image === null) return false;
            // 调色板图先转真彩,后续旋转/缩放才不丢色
            $truecolor = $this->ensureTrueColor($image);
            if ($truecolor !== $image) {
                imagedestroy($image);
                $image = $truecolor;
            }
            // 修正 EXIF 方向(可能返回旋转后的新资源)
            $rotated = $this->orient($image, $source);
            if ($rotated !== $image) {
                imagedestroy($image);
                $image = $rotated;
            }
            // 缩放到目标尺寸
            $resized = $this->resize($image, $dstWidth, $dstHeight);
            // 源资源用完即释放
            imagedestroy($image);
            // 编码写出
            $ok = $this->encode($resized, $dest, $mime, $quality);
            // 释放缩放结果
            imagedestroy($resized);
            // 返回编码结果
            return $ok;
        } finally {
            // 还原内存上限
            if ($oldLimit !== null) @ini_set('memory_limit', $oldLimit);
        }
    }

    /**
     * 按格式解码原图
     *
     * @param string $source 原图路径
     * @param string $mime 原图 MIME
     * @return resource|null 解码后的图像资源,失败返回 null
     */
    private function decode(string $source, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
                // jpeg
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/png':
                // png(带透明通道)
                $image = @imagecreatefrompng($source);
                break;
            case 'image/webp':
                // webp(个别环境编译时未带 libwebp,函数不存在则失败)
                $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
                break;
            default:
                // 不会走到(主类已按 supportedMimes 过滤)
                $image = false;
        }
        // 失败返回 null
        return $image === false ? null : $image;
    }

    /**
     * 调色板图转真彩(palette → truecolor)
     *
     * @param resource $image 图像资源
     * @return resource 真彩图像资源(可能为新资源)
     */
    private function ensureTrueColor($image)
    {
        // 已是真彩直接返回
        if (imageistruecolor($image)) return $image;
        // 建同尺寸真彩画布
        $truecolor = imagecreatetruecolor(imagesx($image), imagesy($image));
        // 保住透明通道
        imagealphablending($truecolor, false);
        imagesavealpha($truecolor, true);
        // 拷贝像素
        imagecopy($truecolor, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        // 返回新资源
        return $truecolor;
    }

    /**
     * 修正 EXIF 方向(手动读取旋转,仅 jpeg 实际携带方向)
     *
     * GD 的角度为逆时针,顺时针 90 度要用 -90。
     *
     * @param resource $image 图像资源(已转真彩)
     * @param string $source 原图路径(读 EXIF 用)
     * @return resource 修正后的图像资源(旋转时可能为新资源)
     */
    private function orient($image, string $source)
    {
        // exif 扩展没装读不出方向
        if (!function_exists('exif_read_data')) return $image;
        // 读取 EXIF
        $exif = @exif_read_data($source);
        // 无方向按原图返回
        if (empty($exif['Orientation'])) return $image;
        // 旋转背景用透明色,旋转 png/webp 时边缘不糊黑底
        $background = imagecolorallocatealpha($image, 0, 0, 0, 127);
        // 按方向逐个修正
        switch ((int)$exif['Orientation']) {
            case 2: // 水平镜像
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3: // 旋转180
                $image = imagerotate($image, 180, $background);
                break;
            case 4: // 垂直镜像
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5: // 转置:顺时针90 + 水平镜像
                $image = imagerotate($image, -90, $background);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6: // 顺时针90
                $image = imagerotate($image, -90, $background);
                break;
            case 7: // 反置:顺时针90 + 垂直镜像
                $image = imagerotate($image, -90, $background);
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 8: // 逆时针90
                $image = imagerotate($image, 90, $background);
                break;
        }
        // 返回(可能为新资源)
        return $image;
    }

    /**
     * 缩放到目标尺寸
     *
     * @param resource $image 图像资源(已修正方向)
     * @param int $dstWidth 目标宽
     * @param int $dstHeight 目标高
     * @return resource 缩放后的新图像资源
     */
    private function resize($image, int $dstWidth, int $dstHeight)
    {
        // 目标画布
        $dst = imagecreatetruecolor($dstWidth, $dstHeight);
        // png/webp 需要保留透明通道:关闭混合 + 保存 alpha
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        // 重采样缩放(目标尺寸恒不大于原图,不存在放大)
        imagecopyresampled($dst, $image, 0, 0, 0, 0, $dstWidth, $dstHeight, imagesx($image), imagesy($image));
        // 返回
        return $dst;
    }

    /**
     * 按原格式编码写出
     *
     * @param resource $image 图像资源
     * @param string $dest 输出路径
     * @param string $mime 原图 MIME
     * @param int $quality 输出质量
     * @return bool 是否成功
     */
    private function encode($image, string $dest, string $mime, int $quality): bool
    {
        switch ($mime) {
            case 'image/png':
                // png 无损,quality 不适用,用 zlib 压缩级别
                return @imagepng($image, $dest, self::PNG_COMPRESSION);
            case 'image/webp':
                // webp(函数缺失按失败处理,主类会降级)
                return function_exists('imagewebp') ? @imagewebp($image, $dest, $quality) : false;
            default:
                // jpeg
                return @imagejpeg($image, $dest, $quality);
        }
    }

    /**
     * 临时上调 memory_limit 到 256M(禁止 ini_set 或已够用时不动)
     *
     * @return string|null 原内存上限(未调整时返回 null,无需还原)
     */
    private function raiseMemory(): ?string
    {
        // 当前上限
        $current = ini_get('memory_limit');
        // 读不到就别动
        if ($current === false || $current === '') return null;
        // 已有 256M 及以上,不用调
        if ($this->memoryBytes($current) >= 268435456) return null;
        // 调到 256M,失败也继续(有些主机禁止 ini_set)
        if (@ini_set('memory_limit', '256M') === false) return null;
        // 返回原值供还原
        return $current;
    }

    /**
     * 将 memory_limit 写法换算为字节数(如 128M)
     *
     * @param string $limit 内存上限字符串
     * @return int 字节数
     */
    private function memoryBytes(string $limit): int
    {
        // 末尾单位
        $unit = strtoupper(substr($limit, -1));
        // 数值部分
        $value = (int)$limit;
        if ($unit === 'K') return $value * 1024;
        if ($unit === 'M') return $value * 1048576;
        if ($unit === 'G') return $value * 1073741824;
        // 无单位按字节
        return $value;
    }
}
