<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Thumbnail;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Imagick 驱动
 *
 * 画质(Lanczos 重采样)与内存表现优于 GD,且支持 autoOrient 自动修正 EXIF 方向;
 * 主类检测到 imagick 扩展时优先走本驱动。
 * 注意:本文件内 PHP 原生 Imagick 相关类一律带全局反斜杠(\Imagick),避免与同类名的本驱动混淆。
 */
class Imagick extends Thumbnail
{
    /**
     * 本驱动支持的原图格式
     *
     * @return string[]
     */
    public function supportedMimes(): array
    {
        // jpeg/png/webp/avif(gif 动图已在主类统一跳过)
        return ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    }

    /**
     * 渲染一张缩图
     *
     * @param string $source 原图路径
     * @param int $dstWidth 目标宽
     * @param int $dstHeight 目标高
     * @param string $dest 输出文件路径(无扩展名,格式由 $mime 决定)
     * @param string $mime 原图 MIME(输出同格式)
     * @param int $quality 输出质量(jpeg/webp/avif 有效)
     * @return bool 是否成功
     */
    public function render(string $source, int $dstWidth, int $dstHeight, string $dest, string $mime, int $quality): bool
    {
        try {
            // 解码原图
            $image = new \Imagick($source);
            // 修正 EXIF 方向
            $this->orient($image);
            // 丢弃元数据(EXIF/ICC 等,既省体积也避免方向信息残留)
            $image->stripImage();
            // 显式指定输出格式(临时文件无扩展名,必须显式告知编码器)
            $image->setImageFormat($this->formatOf($mime));
            // 缩放到目标尺寸(Lanczos,目标尺寸已由主类算好,关闭自适应)
            $image->resizeImage($dstWidth, $dstHeight, \Imagick::FILTER_LANCZOS, 1, false);
            // jpeg/webp/avif 设置质量(png 无损,不适用)
            if ($mime !== 'image/png') {
                // 3.1+ 用 setImageCompressionQuality,旧版退回 setCompressionQuality
                if (method_exists($image, 'setImageCompressionQuality')) {
                    $image->setImageCompressionQuality($quality);
                } else {
                    $image->setCompressionQuality($quality);
                }
            }
            // 写出
            $image->writeImage($dest);
            // 释放
            $image->destroy();
            // 成功
            return true;
        } catch (\Throwable $e) {
            // 任何一步失败(解码/缺 delegate/写出)都按失败处理,由主类降级
            return false;
        }
    }

    /**
     * 修正 EXIF 方向
     *
     * @param \Imagick $image 已解码的图片
     * @return void
     */
    private function orient(\Imagick $image): void
    {
        // 3.2+ 直接用 autoOrient
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
            return;
        }
        // 旧版扩展手动旋转兜底(角度正值=顺时针)
        switch ($image->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPRIGHT:    // 2:水平镜像
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: // 3:旋转180
                $image->rotateImage(new \ImagickPixel(), 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:  // 4:垂直镜像
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTTOP:     // 5:转置(顺时针90 + 水平镜像)
                $image->rotateImage(new \ImagickPixel(), 90);
                $image->flopImage();
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:    // 6:顺时针90
                $image->rotateImage(new \ImagickPixel(), 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: // 7:反置(顺时针90 + 垂直镜像)
                $image->rotateImage(new \ImagickPixel(), 90);
                $image->flipImage();
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:  // 8:逆时针90
                $image->rotateImage(new \ImagickPixel(), -90);
                break;
        }
        // 复位方向标记,避免后续环节重复处理
        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * MIME 转 Imagick 格式名
     *
     * @param string $mime 原图 MIME
     * @return string Imagick 格式名
     */
    private function formatOf(string $mime): string
    {
        switch ($mime) {
            case 'image/png':
                return 'PNG';
            case 'image/webp':
                return 'WEBP';
            case 'image/avif':
                return 'AVIF';
            default:
                return 'JPEG';
        }
    }
}
