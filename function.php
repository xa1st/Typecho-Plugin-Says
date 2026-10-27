<?php
namespace TypechoPlugin\Says;
/**
 * 公共函数库
 * 
 * @package Says
 */
// 防止直接访问
if(!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 生成UUID
 * 
 * @return string 生成的UUID
 */
function generateUUID() {
    return sprintf(
        '%04x%04x%04x%04x%04x%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
/**
 * 格式化说说数据
 * 
 * @param array $say 原始说说数据
 * @return array 格式化后的数据
 */
function formatSayData($say) {
    return [
        'uid' => $say['uuid'],
        'content' => $say['content'],
        'created_at' => intval($say['created_at']),
        'updated_at' => intval($say['updated_at']),
        'up' => intval($say['up']),
        'down' => intval($say['down']),
        'from' => $say['source'] ?: 'web'
    ];
}
/**
 * 将附件 id 入参统一转为整型数组
 *
 * 兼容逗号分隔字符串("1,2,3")和数组两种形式,
 * 避免因 Typecho 1.3 Request::get 的类型匹配检查而丢失参数
 *
 * @param mixed $attachmentIds 逗号分隔字符串或数组
 * @return array 整型 id 数组(已去空值,无效值自动丢弃)
 */
function normalizeAttachmentIds($attachmentIds): array {
    // 空值直接返回空数组
    if (empty($attachmentIds)) return [];
    // 统一拆成字符串列表
    $rawList = is_array($attachmentIds) ? $attachmentIds : explode(',', (string)$attachmentIds);
    // 逐个转整型,丢弃无效值
    $result = [];
    foreach ($rawList as $item) {
        // 去空白转整型,小于等于 0 丢壶
        $id = (int)trim((string)$item);
        if ($id > 0) $result[] = $id;
    }
    // 去重后返回
    return array_values(array_unique($result));
}

function validateSayContent($content) {
    // 验证内容
    if (empty($content))  return [false, '说说内容不能为空'];
    // 说说内容不能太长
    if (mb_strlen($content, 'UTF-8') > 1000) return [false, '内容太长（最多1000字符）'];
    // 返回判定结果
    return [true, ''];
}

/**
 * 返回JSON
 * 
 */
function outputJson($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
}

/**
 * 将SQL脚本拆分为单条语句
 * 
 * Typecho的Db适配器(Mysqli/Pdo/SQLite)只支持执行单条语句,
 * 因此建表脚本需先去掉注释、按分号拆分,再逐条执行。
 * 约定:注释独占整行(以--开头),每条语句以行尾分号结束。
 * 
 * @param string $sql 原始SQL脚本内容
 * @return array 单条SQL语句列表(已trim,不含空语句)
 */
function splitSqlStatements(string $sql): array {
    // 语句列表
    $statements = [];
    // 当前累积的语句
    $current = '';
    // 按行遍历(兼容 \n / \r\n)
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        // 去除首尾空白
        $trimmed = trim($line);
        // 跳过空行和注释行
        if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
        // 累积到当前语句
        $current .= $trimmed . "\n";
        // 行尾分号表示本语句结束
        if (substr(rtrim($trimmed), -1) === ';') {
            // 收款
            $statements[] = $current;
            // 重置
            $current = '';
        }
    }
    // 最后一条未以分号结尾的语句(兜底)
    if (trim($current) !== '') $statements[] = $current;
    // 逐条去空白返回
    return array_map('trim', $statements);
}

/**
 * 生成附件目标路径
 * 
 * 根据配置的存储路径(storagePath,date 格式)和重命名规则(fileNewName)生成目标路径
 * 例如 storagePath=Y/md,fileNewName=uniqid → 2025/1014/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d.png
 * 
 * @param string $filename 原始文件名(用于提取扩展名)
 * @param string $fallbackExt 文件名无扩展名时的兜底扩展名
 * @return string 目标路径,不含前导斜杠
 */
function generateTargetPath(string $filename, string $fallbackExt = 'bin'): string {
    // 插件配置
    $options = \Utils\Helper::options()->plugin('Says');
    // 存储路径规则,默认 Y/md,参考 PHP date 函数
    $storagePath = !empty($options->storagePath) ? $options->storagePath : 'Y/md';
    // 扩展名:优先取文件名中的,其次用兜底
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (empty($ext)) $ext = $fallbackExt;
    // 生成文件名(按配置规则)
    $name = uniqid();
    // 目录前缀(去尾部斜杠,防止双斜杠)
    $prefix = rtrim(date($storagePath), '/');
    // 目标路径:目录 + 文件名
    return $prefix . '/' . $name . '.' . $ext;
}

/**
 * 获取允许上传的文件类型列表
 * 
 * 解析配置的逗号分隔字符串为小写数组
 * 
 * @return array 扩展名数组,如 ['jpg', 'jpeg', 'png']
 */
function getAllowedFileTypes(): array {
    // 插件配置
    $options = \Utils\Helper::options()->plugin('Says');
    // 配置值,默认常见图片类型
    $types = trim($options->allowedFileTypes ?: '');
    // 空则返回默认
    if ($types === '') $types = 'jpg,jpeg,png,gif,webp,avif';
    // 转小写数组,去空项
    return array_values(array_filter(array_map('trim', explode(',', strtolower($types)))));
}

/**
 * 获取存储实例
 * 
 * 根据插件配置的图床类型(storage)和参数(storageConfig),实例化对应的存储类
 * 各存储所需配置的校验、默认值、密钥读取都在各自构造函数内完成,不在此重复
 * 
 * @return \TypechoPlugin\Says\Storage\Storage 存储实例
 * @throws \Exception 配置不完整或类型未知时抛出异常
 */
function getStorage() {
    // 插件配置
    $options = \Utils\Helper::options()->plugin('Says');
    // 图床类型
    $type = isset($options->storage) ? $options->storage : 'local';
    // 解析 JSON 参数,失败则给空数组
    $config = json_decode($options->storageConfig ?: '', true);
    if (!is_array($config)) $config = [];
    // 完整类名(命名空间 + 首字母大写的类型)
    $classname = '\\TypechoPlugin\\Says\\Storage\\' . ucfirst($type);
    // 看类是否存在,不存在直接抛出异常
    if (!class_exists($classname)) throw new \Exception('未知的图床类型: ' . $type);
    // 实例化(校验 + 默认值 + 密钥读取全在构造函数内)
    return new $classname($config);
}
