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
 * 根据高级规则匹配源字符串，支持正则捕获组引用
 * 
 * @param string $source 源字符串
 * @param string $rule 规则字符串，格式为多行的"名称 \\index|/正则表达式/"
 * @return string 匹配的名称或正则捕获组的值，如果没有匹配则返回\"未知\"
 */
function getSource($source, $rule = '') {
    // 如果没有，直接返回未知
    if (empty($source))  return '';
    // 获取规则字符串，取消转义
    if (empty($rule)) $rule = html_entity_decode(\Utils\Helper::options()->plugin('Says')->platform);
    // 将规则字符串按行分割
    $ruleLines = explode("\n", $rule);
    // 按行拆分
    foreach ($ruleLines as $line) {
        // 去除行首尾空白
        $line = trim($line);
        // 跳过空行
        if (empty($line)) continue;
        // 提取名称和规则部分
        if (preg_match('/^(.*?)\|\|(.*?)/U', $line, $ruleMatches)) {
            // 默认名称
            $defaultName = $ruleMatches[1]; 
            // 正则表达式内容
            $pattern = $ruleMatches[2]; 
            // 修复正则表达式中的\\d等转义字符（假设d+实际上应该是\\d+）
            // $pattern = str_replace('d+', '\\d+', $pattern);
            //$pattern = str_replace('s', '\\s', $pattern);
            // 使用正则表达式匹配源字符串
            if (preg_match('/' . $pattern . '/i', $source, $matches)) {
                // 核心替换逻辑：动态解析 \\数字 并映射到 $matches
                $defaultName = preg_replace_callback(
                    '/\\\\\\\\\d+/',  // 匹配 \\数字（如 \\1, \\2）
                    function($m) use ($matches) {
                        $index = (int)substr($m[0], 2);  // 提取数字部分
                        return $matches[$index] ?? $m[0]; // 存在则替换，否则保留原文本
                    },
                    $defaultName
                );
                return $defaultName; // 返回处理后的值
            }
        }
    }
    // 如果没有匹配到任何规则，返回未知
    return '';
}

/**
 * 检测用户代理字符串中的操作系统
 * 
 * @param string $userAgent 用户代理字符串，用于识别客户端操作系统和浏览器信息
 * @return array 包含操作系统和浏览器信息的数组
 */
function getOS($userAgent = '') {
    // 返回值
    $result = ['os' => 'Unknown', 'browser' => 'Unknown'];
    // 操作系统检测 - 用一个正则匹配所有可能的操作系统
    if (preg_match('/(?:Windows NT (\d+\.\d+)|iPhone|iPad|Macintosh|Mac OS X|Android|Linux)/i', $userAgent, $osMatches)) {
        switch (true) {
            case isset($osMatches[1]):
                // Windows 版本映射
                $winVersions = ['10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8', '6.1' => 'Windows 7', '6.0' => 'Windows Vista', '5.1' => 'Windows XP'];
                $result['os'] = $winVersions[$osMatches[1]] ?? 'Windows';
                break;
            case stripos($osMatches[0], 'iPhone') !== false:
                $result['os'] = 'iOS';
                break;
            case stripos($osMatches[0], 'iPad') !== false:
                $result['os'] = 'iPadOS';
                break;
            case stripos($osMatches[0], 'Mac') !== false:
                $result['os'] = 'macOS';
                break;
            case stripos($osMatches[0], 'Android') !== false:
                $result['os'] = 'Android';
                break;
            case stripos($osMatches[0], 'Linux') !== false:
                $result['os'] = 'Linux';
                break;
         }
    }
    return $result['os'];
}

/**
 * 获取用户浏览器信息
 * 通过解析用户代理字符串（User Agent）来判断用户正在使用的浏览器类型及版本
 * 
 * @param string $userAgent 用户代理字符串，默认为空，如果为空将使用当前请求的用户代理
 * @return string 返回浏览器名称及版本，如果没有匹配到已知的浏览器，则返回空字符串
 */
function getBrowser($userAgent = '') {
    // 浏览器检测 - 用一个正则匹配所有主流浏览器及版本
    if (preg_match('/(Edg|Edge)\/(\d+)\.|Chrome\/(\d+)\.|Firefox\/(\d+)\.|Version\/(\d+)\..*Safari|MSIE (\d+)\.|rv:(\d+)\..*Trident/i', $userAgent, $browserMatches)) {
        if (!empty($browserMatches[1]) && !empty($browserMatches[2])) {
            // Edge
            $result['browser'] = 'Microsoft Edge ' . $browserMatches[2];
        } elseif (!empty($browserMatches[3])) {
            // Chrome
            $result['browser'] = 'Google Chrome ' . $browserMatches[3];
        } elseif (!empty($browserMatches[4])) {
            // Firefox
            $result['browser'] = 'Mozilla Firefox ' . $browserMatches[4];
        } elseif (!empty($browserMatches[5])) {
            // Safari
            $result['browser'] = 'Apple Safari ' . $browserMatches[5];
        } elseif (!empty($browserMatches[6])) {
            // IE (MSIE)
            $result['browser'] = 'IE ' . $browserMatches[6];
        } elseif (!empty($browserMatches[7])) {
            // IE (Trident)
            $result['browser'] = 'IE ' . $browserMatches[7];
        }
    }
    return $result['browser'];
}


/**
 * 根据用户代理获取平台信息
 * 
 * 此函数首先尝试解析用户代理以获取来源信息如果解析失败或结果为空，
 * 则根据配置返回默认的操作系统信息、浏览器信息或自定义内容
 * 
 * @param string $agent 用户代理字符串，默认为空
 * @return string 平台信息或自定义内容，如果没有配置则返回空字符串
 */
function getPlatform($agent = '') {
    // 直接解析用户代理获取来源信息
    $result = getSource($agent);
    if (!empty($result)) return $result;
    
    // 获取默认来源配置
    $defaultSource = \Utils\Helper::options()->plugin('Says')->defaultSource;
    
    // 根据配置处理来源信息
    if (empty($defaultSource)) return '';
    if ($defaultSource == '1') return getOS($agent);
    if ($defaultSource == '2') return getBrowser($agent);
    if ($defaultSource == '3') return getOS($agent) . ' ' . getBrowser($agent);
    
    // 返回自定义内容
    return $defaultSource;
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
function splitSqlStatements(string $sql): array
{
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
 * 例如 storagePath=Y/md,fileNewName=uuid → 2025/1014/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d.png
 * 
 * @param string $filename 原始文件名(用于提取扩展名)
 * @param string $fallbackExt 文件名无扩展名时的兜底扩展名
 * @return string 目标路径,不含前导斜杠
 */
function generateTargetPath(string $filename, string $fallbackExt = 'bin'): string
{
    // 插件配置
    $options = \Utils\Helper::options()->plugin('Says');
    // 存储路径规则,默认 Y/md,参考 PHP date 函数
    $storagePath = !empty($options->storagePath) ? $options->storagePath : 'Y/md';
    // 重命名规则,默认 uuid
    $fileNewName = !empty($options->fileNewName) ? $options->fileNewName : 'uuid';
    // 扩展名:优先取文件名中的,其次用兜底
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (empty($ext)) $ext = $fallbackExt;
    // 生成文件名(按配置规则)
    switch ($fileNewName) {
        case 'unique':
            // 微秒唯一ID(同秒也不撞名)
            $name = uniqid();
            break;
        case 'uuid':
        default:
            // UUID
            $name = generateUUID();
            break;
    }
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
function getAllowedFileTypes(): array
{
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
