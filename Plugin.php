<?php
namespace TypechoPlugin\Says;

use Typecho\Plugin\PluginInterface;
use Typecho\Db;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 用于typecho的说说插件
 * 
 * @package Says
 * @author 猫东东
 * @version 1.2.4
 * @link https://github.com/xa1st/Typecho-Plugin-Says
 */
class Plugin implements PluginInterface {
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate() {
        // 获取所有 Memo (GET)
        Helper::addRoute('says_list', '/memos/api/v1/memo', '\TypechoPlugin\Says\Action', 'getMemos');
        // 用户登陆
        Helper::addRoute('says_auth', '/memos/api/v1/auth/status', '\TypechoPlugin\Says\Action', 'authStatus');
        // 发布说说
        Helper::addRoute('says_create', '/memos/api/v1/memos', '\TypechoPlugin\Says\Action', 'createMemo');
        // 后台管理相关
        Helper::addPanel(3, 'Says/Manage.php', _t('说说'), _t('管理你的说说'), 'administrator');
        // 后台管理页面
        Helper::addAction('says-manage', '\TypechoPlugin\Says\Manage');
        // 创建数据表
        try {
            $db = Db::get();
            
            // 检查表是否已经存在
            $prefix = $db->getPrefix();
            $tableName = $prefix . 'says';
            $adapterName = get_class($db->getAdapter());
            
            try {
                if (strpos($adapterName, 'SQLite') !== false) {
                    // SQLite
                    $tables = $db->fetchAll($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = '{$tableName}'"));
                } else {
                    // MySQL
                    $tables = $db->fetchAll($db->query("SHOW TABLES LIKE '{$tableName}'"));
                }
                if (!empty($tables)) {
                    return _t('说说插件数据表已存在，跳过创建。');
                }
            } catch (\Exception $e) {
                // 如果查询失败，继续创建表
            }
            
            // 检测数据库类型
            $adapterName = get_class($db->getAdapter());
            
            // SQLite
            if (strpos($adapterName, 'SQLite') !== false) {
                $db->query("CREATE TABLE IF NOT EXISTS " . $db->getPrefix() . "says (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL DEFAULT 0,
                    content TEXT NOT NULL,
                    agent TEXT DEFAULT NULL,
                    created_at INTEGER NOT NULL DEFAULT 0,
                    updated_at INTEGER NOT NULL DEFAULT 0,
                    status INTEGER NOT NULL DEFAULT 1,
                    uuid VARCHAR(36) NOT NULL,
                    ip VARCHAR(50) DEFAULT NULL,
                    source VARCHAR(255) DEFAULT NULL
                )");
                // 创建索引
                $db->query("CREATE UNIQUE INDEX IF NOT EXISTS " . $db->getPrefix() . "says_uuid ON " . $db->getPrefix() . "says (uuid)");
                $db->query("CREATE INDEX IF NOT EXISTS " . $db->getPrefix() . "says_status ON " . $db->getPrefix() . "says (status)");
                $db->query("CREATE INDEX IF NOT EXISTS " . $db->getPrefix() . "says_created_at ON " . $db->getPrefix() . "says (created_at)");
            } 
            // MySQL
            else {
                $db->query("CREATE TABLE IF NOT EXISTS `{$db->getPrefix()}says` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL DEFAULT 0,
                    `content` text NOT NULL,
                    `agent` text DEFAULT NULL,
                    `created_at` int(11) NOT NULL DEFAULT 0,
                    `updated_at` int(11) NOT NULL DEFAULT 0,
                    `status` tinyint(1) NOT NULL DEFAULT 1,
                    `uuid` varchar(36) NOT NULL,
                    `ip` varchar(50) DEFAULT NULL,
                    `source` varchar(255) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uuid` (`uuid`),
                    KEY `status` (`status`),
                    KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (\Exception $e) {
            throw new PluginException('数据表创建失败: ' . $e->getMessage());
        }
        
        return _t('说说插件激活成功！数据表已创建。');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {
        // 移除路由
        Helper::removeRoute('says_list');
        Helper::removeRoute('says_auth');
        Helper::removeRoute('says_create');
        // 移除后台菜单和操作
        Helper::removePanel(3, 'Says/Manage.php');
        Helper::removeAction('says-manage');
        
        // 检查是否需要删除数据表
        $options = Helper::options()->plugin('Says');
        if ($options->deleteTable) {
            try {
                $db = Db::get();
                $tableName = $db->getPrefix() . 'says';
                
                // 根据数据库类型删除表和索引
                $adapterName = get_class($db->getAdapter());
                if (strpos($adapterName, 'SQLite') !== false) {
                    // SQLite需要先删除索引
                    $db->query("DROP INDEX IF EXISTS " . $db->getPrefix() . "says_uuid");
                    $db->query("DROP INDEX IF EXISTS " . $db->getPrefix() . "says_status");
                    $db->query("DROP INDEX IF EXISTS " . $db->getPrefix() . "says_created_at");
                }
                // 删除表
                $db->query("DROP TABLE IF EXISTS `{$tableName}`");
                return _t('说说插件已禁用，数据表已删除。');
            } catch (\Exception $e) {
                throw new PluginException('数据表删除失败: ' . $e->getMessage());
            }
        }
        
        return _t('说说插件已禁用。');
    }

     /**
     * 获取插件配置面板
     */
    public static function config(Form $form) {
        // 是否打开前台提交的功能，关闭后只在后台提交
        $isOpen = new Radio(
            'isOpen', 
            ['1' => _t('打开'), '0' => _t('关闭')], 
            0,
            _t('是否开启API远程提交'),
            _t('如果只想后台提交说说，请关闭此选项')
        );
        $form->addInput($isOpen);

        // 用于访问的令牌
        $token = new Text('token', null, '', _t('API访问令牌'), _t('用于API接口认证的令牌，建议使用32位以上的随机字符串'));
        $form->addInput($token);

        // 用于发说说的用户id
        $userId = new Text('userId', null, '', _t('用户ID'), _t('用于API接口认证的用户ID，请填写用户ID，非用户名'));
        $form->addInput($userId);

        // 小尾巴设置
        $platform = new Textarea(
            'platform',
            NULL,
            _t("绿泡泡 V\\\\1||MicroMessenger\/(.*?)\(\n全能的Windows||Windows精致的MacOS||Mac\n肾疼的IPhone||iPhone\n泡面盖子IPad||iPad\n曾经卡死的Android||Android\n真正的神Linux||Linux"),
            _t('来源名称'),
            _t('要识别的小尾巴，格式 [浏览器名称]||[要包含的字符串]，多个小尾巴用换行分隔，例如：Chrome浏览器|Chrome/，优先级从上往下')
        );
        $form->addInput($platform);

        // 默认来源
        $defaultSource = new Text(
            'defaultSource',
            NULL,
            '',
            _t('默认来源'),
            _t('如果所有的都没匹配到，则显示这个默认来源，可选值：直接留空:不显示来源 1:显示操作系统 2:显示浏览器 3:操作系统+浏览器，如果要显示自定义，直接 填写自定义内容')
        );
        $form->addInput($defaultSource);

        // 是否在禁用插件时删除数据表
        $deleteTable = new Radio(
            'deleteTable',
            ['1' => _t('是'), '0' => _t('否')],
            '0',
            _t('禁用插件时是否删除数据表'),
            _t('如果选择是，在禁用插件时会删除插件创建的数据表。请谨慎选择！')
        );
        $form->addInput($deleteTable);
    }

    /**
     * 个人用户配置面板
     */
    public static function personalConfig(Form $form){}

    /**
     * 调用插件，放到指定的页面中调用即可
     * 
     */
    public static function render($perPage = 10, $dom = '#says', $url = '/memos/', $config = []) { 
        // 获取插件URL
        $pluginUrl = Helper::options()->pluginUrl . '/Says';
        // 加载样式
        $css = $config['css'] ?? $pluginUrl . '/static/says.css?ver=' . time();
        echo '<link rel="stylesheet" href="' . $css . '"/>';
        // 加载markdown库
        $markdown = $config['markdown'] ?? $pluginUrl . '/static/markd.min.js';
        echo '<script src="' . $markdown . '"></script>';
        // 加载说说用的js
        $js = $config['js'] ?? $pluginUrl . '/static/says.min.js?ver=' . time();
        echo '<script src="' . $js . '"></script>';
        // 创建一个MemoLoader对象
        echo '<script>document.addEventListener("DOMContentLoaded", function(){ const memoLoader = new MemoLoader({memos: "' . $url . '", limit: ' . $perPage . ', domId: "' . $dom . '"});});</script>';
    }
}