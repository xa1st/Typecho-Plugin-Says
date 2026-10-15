<?php
namespace TypechoPlugin\Says;

use Typecho\Plugin\PluginInterface;
use Typecho\Db;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Plugin\Exception as PluginException;
use Utils\Helper;

// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 用于typecho的说说插件
 * 
 * @package Says
 * @author 猫东东
 * @version 2.0.0
 * @License Mulan PSL v2
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
        // 上传附件
        Helper::addRoute('says_upload_attachment', '/memos/api/v1/attachment', '\TypechoPlugin\Says\Action', 'uploadAttachment');
        // 获取所有标签
        Helper::addRoute('says_tags', '/memos/api/v1/tags', '\TypechoPlugin\Says\Action', 'getTags');
        // 后台管理相关
        Helper::addPanel(3, 'Says/Manage.php', _t('说说'), _t('管理你的说说'), 'administrator');
        // 后台管理页面
        Helper::addAction('says-manage', '\TypechoPlugin\Says\Manage');
        // 获取数据库连接
        $db = Db::get();
        // 读取当前数据库的适配器类型
        $adapter = $db->getAdapterName();
        // 默认为空
        $adapterName = '';
        // 判断适配器类型
        if (strpos($adapter, 'Mysql') !== false) $adapterName = 'mysql';
        if (strpos($adapter, 'SQLite') !== false) $adapterName = 'sqlite';
        if (strpos($adapter, 'PostgreSQL') !== false) $adapterName = 'pgsql';
        // 如果没有适配器类型，抛出异常
        if (!$adapterName) throw new PluginException('不支持的数据库类型: ' . $adapter);
        // 获取当前目录
        $dir = dirname(__FILE__);
        // 获取当前目录下sql下的对应文件
        $sqlfile =  $dir . '/sql/' . strtolower($adapterName) . '.sql';
        // 判定有没有对应的数据表文件，如果没有直接抛出异常
        if (!file_exists($sqlfile)) throw new PluginException('缺少必要的SQL文件，请检查插件目录下的sql文件夹是否存在。' . $sqlfile);
        // 读取SQL文件内容并替换前缀
        $sql = file_get_contents($sqlfile);
        // 替换前缀
        $sql = str_replace('{{prefix}}', $db->getPrefix(), $sql);
        // Typecho的Db适配器(Mysqli/Pdo/SQLite)均不支持一次执行多条语句,
        // 需将SQL文件拆分为单条语句逐条执行(见function.php的splitSqlStatements)
        foreach (splitSqlStatements($sql) as $statement) {
            $db->query($statement);
        }
        // 返回成功信息
        return _t('说说插件激活成功!数据表已创建。');
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
        Helper::removeRoute('says_upload_attachment');
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

        // 图床管理
        $storage = new Select(
            'storage',
            ['local' => _t('本地'), 'backblaze' => _t('Backblaze B2'), 'upyun' => _t('又拍云')],
            'local',
            _t('图床管理'),
            _t('请选择一个图床,用于上传图片')
        );
        $form->addInput($storage);

        // 图床参数设置，Local直接留空
        $storageConfig = new Textarea(
            'storageConfig',
            NULL,
            '{"bucket":"", "endpoint":"", "url":"", "path": ""}',
            _t('图床参数设置'),
            _t('请根据所选图床的要求填写相应的参数配置，Local图床可以留空，不要写敏感信息，AK和SK直接写入config.inc.php中比较好')
        );
        $form->addInput($storageConfig);

        // 附件存储路径
        $storagePath = new Text(
            'storagePath',
            NULL,
            'Y/md',
            _t('附件存储路径'),
            _t('请填写附件子目录格式,仅支持PHP的date()格式符,例如:Y/md(Y年/md月日),只用date格式符,不支持自定义英文目录。Local图床固定存放在站点usr/uploads目录下,存储路径为其下的子目录;云图床则是存储桶内的路径')
        );
        $form->addInput($storagePath);

        // 文件重命名规则
        $fileNewName = new Select(
            'fileNewName',
            ['uuid' => _t('UUID'), 'unique' => _t('唯一ID')],
            'uuid',
            _t('文件重命名规则'),
            _t('选择上传文件的重命名规则')
        );
        $form->addInput($fileNewName);

        // 最大上传文件大小
        $maxFileSize = new Text(
            'maxFileSize',
            NULL,
            '2',
            _t('最大上传文件大小'),
            _t('请填写最大上传文件大小，单位是：M，例如：2表示2M，留空表示不限制大小')
        );
        $form->addInput($maxFileSize);

        // 可上传文件类型
        $allowedFileTypes = new Textarea(
            'allowedFileTypes',
            NULL,
            'jpg,jpeg,png,gif,webp,avif',
            _t('可上传文件类型'),
            _t('请填写可上传的文件类型，多个类型用逗号分隔，例如：jpg,jpeg,png,gif')
        );
        $form->addInput($allowedFileTypes);

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
        $css = $config['css'] ?? $pluginUrl . '/static/says.css';
        echo '<link rel="stylesheet" href="' . $css . '"/>';
        // 加载markdown库
        $markdown = $config['markdown'] ?? $pluginUrl . '/static/markd.min.js';
        echo '<script src="' . $markdown . '"></script>';
        // 加载说说用的js
        $js = $config['js'] ?? $pluginUrl . '/static/says.min.js';
        echo '<script src="' . $js . '"></script>';
        // 创建一个MemoLoader对象
        echo '<script>document.addEventListener("DOMContentLoaded", function(){ const memoLoader = new MemoLoader({memos: "' . $url . '", limit: ' . $perPage . ', domId: "' . $dom . '"});});</script>';
    }
}