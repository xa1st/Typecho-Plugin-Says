<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says\Thumbnail;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 缩略图抽象主类(模板方法 + 驱动工厂)
 *
 * 职责:生成缩图前的公共判定与尺寸计算,真正的像素处理交给子类驱动:
 *   - 驱动选择:装了 Imagick 扩展优先 Imagick(画质/内存更优),否则退回 GD(几乎必装)
 *   - 可缩性判定:GIF 动图重编码会丢动画,统一跳过;像素超过 MAX_PIXELS 直接跳过,
 *     防止 48MP 手机照解码时撑爆 memory_limit
 *   - 尺寸计算:小图(thumb)等比缩入 200 见方(前端按 100px 显示,按 2x 屏生成保证清晰);
 *     大图(large)仅在宽超 1920 时按宽度等比压缩(高度不设限,长截图保持可读)
 *   - 不放大:原图本身小于目标尺寸时不生成对应缩图
 *   - EXIF 方向:方向 5-8(横竖持机)的显示宽高对调,先换算再算尺寸,避免躺倒缩图
 *   - 格式策略:输出与原图同格式(纯缩放重编码),透明度/兼容性最稳;驱动编不动的格式跳过
 *
 * 缩图目标路径由 Thumbnail::deriveTarget() 推导(扩展名前插标记):
 *   2025/1014/a.png → 2025/1014/a.thumb.png(小图)、2025/1014/a.large.png(大图)
 *
 * 调用约定(供存储层对接):
 *   - create() 返回的 path 均为系统临时文件,调用方上传完成后负责 unlink
 *   - large 为 null 表示原图宽未超上限(或大图生成失败),直接复用原图 URL
 *   - 返回 null 表示本图不适合缩(或小图生成失败),调用方应整体回退原图
 */
abstract class Thumbnail
{
    /** 小图最长边(等比缩入该见方,只缩不放) */
    public const SMALL_SIZE = 200;

    /** 大图宽度上限:宽超过该值才生成大图 */
    public const LARGE_WIDTH = 1920;

    /** 小图输出质量(jpeg/webp 有效) */
    public const SMALL_QUALITY = 80;

    /** 大图输出质量(jpeg/webp 有效) */
    public const LARGE_QUALITY = 85;

    /** PNG 的 zlib 压缩级别(无损格式,0-9) */
    public const PNG_COMPRESSION = 6;

    /** 像素总量护栏:超过该值直接跳过(约 2000 万,再大解码内存会失控) */
    public const MAX_PIXELS = 20000000;

    /**
     * 生成缩图的统一入口(静态工厂)
     *
     * @param string $filePath 原图本地路径(含 base64 转存的临时文件)
     * @return array|null null 表示不适合缩或小图生成失败,调用方应整体回退原图;
     *   成功返回:['thumb' => ['path' => 临时文件, 'width' => int, 'height' => int],
     *             'large' => 同上|null(宽未超上限或大图生成失败时为 null,复用原图)]
     *   返回的临时文件由调用方负责 unlink
     */
    public static function create(string $filePath): ?array
    {
        // 先量尺寸(getimagesize 只读文件头,不整图解码,开销极小)
        $info = @getimagesize($filePath);
        // 非图片或读不出尺寸,直接放弃
        if (empty($info) || empty($info['mime'])) return null;
        // 原图格式
        $mime = (string)$info['mime'];
        // GIF 动图:重编码会丢动画,统一跳过
        if ($mime === 'image/gif') return null;
        // 选择驱动:有 Imagick 用 Imagick,没有退回 GD
        $driver = extension_loaded('imagick') ? new Imagick() : new Gd();
        // 驱动编不动的格式(如 GD 遇到 avif),放弃
        if (!in_array($mime, $driver->supportedMimes(), true)) return null;
        // 显示宽高
        $width = (int)$info[0];
        $height = (int)$info[1];
        // 无效尺寸
        if ($width < 1 || $height < 1) return null;
        // 像素护栏:超大图解码内存失控,直接跳过
        if ($width * $height > self::MAX_PIXELS) return null;
        // EXIF 方向 5-8 的图(横竖持机)显示宽高对调,先换算再算尺寸
        if (self::isRotated($filePath, $mime)) {
            list($width, $height) = [$height, $width];
        }
        // 小图目标尺寸:等比缩入 SMALL_SIZE 见方(只缩不放)
        $thumbSize = null;
        if (max($width, $height) > self::SMALL_SIZE) {
            $scale = self::SMALL_SIZE / max($width, $height);
            $thumbSize = [(int)round($width * $scale), (int)round($height * $scale)];
        }
        // 大图目标尺寸:仅宽超上限才生成(高度不设限,长截图保持可读)
        $largeSize = null;
        if ($width > self::LARGE_WIDTH) {
            $largeSize = [self::LARGE_WIDTH, (int)round($height * self::LARGE_WIDTH / $width)];
        }
        // 两级都不需要(原图本身够小),没有可生成的缩图
        if ($thumbSize === null && $largeSize === null) return null;
        // 先保证小图生成成功(信息流的主角),失败则整体放弃
        $thumb = null;
        if ($thumbSize !== null) {
            $thumb = self::renderToTemp($driver, $filePath, $thumbSize, $mime, self::SMALL_QUALITY);
            if ($thumb === null) return null;
        }
        // 大图允许失败降级:null 表示前端复用原图当大图
        $large = null;
        if ($largeSize !== null) {
            $large = self::renderToTemp($driver, $filePath, $largeSize, $mime, self::LARGE_QUALITY);
        }
        // 返回结果
        return ['thumb' => $thumb, 'large' => $large];
    }

    /**
     * 由原图目标路径推导缩图目标路径
     *
     * 规则:扩展名前插入标记。只看原图路径即可确定缩图位置,删除时无需查库。
     *
     * @param string $target 原图目标路径(存储相对路径)
     * @param string $type 'thumb' 小图 / 'large' 大图
     * @return string 缩图目标路径,如 2025/1014/a.png → 2025/1014/a.thumb.png
     */
    public static function deriveTarget(string $target, string $type): string
    {
        // 标记
        $marker = $type === 'large' ? '.large' : '.thumb';
        // 无扩展名的兜底:直接追加标记
        $dot = strrpos($target, '.');
        if ($dot === false) return $target . $marker;
        // 扩展名前插入
        return substr($target, 0, $dot) . $marker . substr($target, $dot);
    }

    /**
     * 渲染一张缩图到临时文件(统一封装驱动调用与临时文件管理)
     *
     * @param Thumbnail $driver 选定的驱动
     * @param string $filePath 原图路径
     * @param array $dstSize [目标宽, 目标高]
     * @param string $mime 原图 MIME(输出同格式)
     * @param int $quality 输出质量
     * @return array|null 成功返回 ['path'=>临时文件, 'width'=>int, 'height'=>int],失败返回 null(临时文件已清理)
     */
    private static function renderToTemp(Thumbnail $driver, string $filePath, array $dstSize, string $mime, int $quality): ?array
    {
        // 创建临时文件(无扩展名,由驱动内部显式指定编码格式)
        $temp = tempnam(sys_get_temp_dir(), 'says_thumb_');
        // 创建失败
        if ($temp === false) return null;
        try {
            // 交给驱动:解码 → 修正方向 → 缩放 → 编码
            $ok = $driver->render($filePath, $dstSize[0], $dstSize[1], $temp, $mime, $quality);
        } catch (\Throwable $e) {
            // 驱动内部抛出的任何异常(缺 delegate 等)都按失败处理
            $ok = false;
        }
        // 失败清理临时文件
        if (!$ok) {
            @unlink($temp);
            return null;
        }
        // 返回结果
        return ['path' => $temp, 'width' => $dstSize[0], 'height' => $dstSize[1]];
    }

    /**
     * 判断图片是否带 EXIF 旋转(方向 5-8,显示时宽高对调)
     *
     * 仅 jpeg 实际携带方向信息;exif 扩展不可用时按未旋转处理(躺倒是可接受的降级)。
     *
     * @param string $filePath 原图路径
     * @param string $mime 原图 MIME
     * @return bool 是否带旋转
     */
    private static function isRotated(string $filePath, string $mime): bool
    {
        // exif 扩展没装,或非 jpeg,读不出方向
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return false;
        // 读取 EXIF
        $exif = @exif_read_data($filePath);
        // 读不到按未旋转处理
        if (empty($exif['Orientation'])) return false;
        // 方向 5/6/7/8 为带旋转(宽高对调)
        $orientation = (int)$exif['Orientation'];
        return $orientation >= 5 && $orientation <= 8;
    }

    /**
     * 驱动支持的原图 MIME 列表(输出与原图同格式,编不动的格式直接跳过)
     *
     * @return string[]
     */
    abstract public function supportedMimes(): array;

    /**
     * 渲染一张缩图(子类实现)
     *
     * 解码 → 按需要修正 EXIF 方向 → 缩放到目标尺寸(不放大不裁剪) → 按原格式编码写出
     *
     * @param string $source 原图路径
     * @param int $dstWidth 目标宽
     * @param int $dstHeight 目标高
     * @param string $dest 输出文件路径(无扩展名,格式由 $mime 决定)
     * @param string $mime 原图 MIME(输出同格式)
     * @param int $quality 输出质量(jpeg/webp 有效,png 无损忽略)
     * @return bool 是否成功
     */
    abstract public function render(string $source, int $dstWidth, int $dstHeight, string $dest, string $mime, int $quality): bool;
}
