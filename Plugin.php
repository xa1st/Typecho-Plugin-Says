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

// 引入公共函数
require_once 'function.php';

/**
 * 用于typecho的说说插件
 * 
 * @package Says
 * @author 猫东东
 * @version 2.1.1
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
        Helper::addPanel(2, 'Says/Manage.php', _t('发布说说'), _t('管理你的说说'), 'administrator');
        // 后台管理页面
        Helper::addAction('says-manage', '\TypechoPlugin\Says\Manage');
        // 获取数据库连接
        $db = Db::get();
        try {
            // 运行安装的SQL文件
            self::runSql($db, 'install');
            // 激活时补全缺失列(兼容老版本升级)
            self::upgrade($db);
        } catch (PluginException $e) {
            throw new PluginException('说说插件激活失败: ' . $e->getMessage());
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
        Helper::removeRoute('says_tags');
        // 移除后台菜单和操作
        Helper::removePanel(2, 'Says/Manage.php');
        Helper::removeAction('says-manage');
        
        // 检查是否需要删除数据表
        $options = Helper::options()->plugin('Says');
        // 如果选择了删除数据表，则执行删除操作
        if ($options->deleteTable) {
            try {
                self::runSql(Db::get(), 'remove');
            } catch (PluginException $e) {
                return _t('说说插件已禁用，但删除数据表失败: ' . $e->getMessage());
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
        $token = new Textarea('token', null, '', _t('用户id和API访问令牌每行1个，格式 id|token'), _t('用于API接口认证的用户ID和对应令牌，令牌建议使用32位以上的随机字符串'));
        $form->addInput($token);

        // 小尾巴设置
        $platform = new Textarea(
            'platform',
            NULL,
            _t("绿泡泡 V\\\\1||MicroMessenger\/(.*?)\(\n全能的Windows||Windows精致的MacOS||Mac\n肾疼的IPhone||iPhone\n泡面盖子IPad||iPad\n曾经卡死的Android||Android\n真正的神Linux||Linux"),
            _t('来源名称'),
            _t('要识别的小尾巴，格式 [要显示的名称]||[要包含的字符串]，多个小尾巴用换行分隔，例如：Chrome浏览器|Chrome/，优先级从上往下')
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
        $allowedFileTypes = new Text(
            'allowedFileTypes',
            NULL,
            'jpg,jpeg,png,gif,webp,avif',
            _t('可上传文件类型'),
            _t('请填写可上传的文件类型，多个类型用逗号分隔，例如：jpg,jpeg,png,gif')
        );
        $form->addInput($allowedFileTypes);

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
        $js = $config['js'] ?? $pluginUrl . '/static/says.js';
        echo '<script src="' . $js . '"></script>';
        // 创建一个MemoLoader对象
        echo '<script>document.addEventListener("DOMContentLoaded", function(){ const memoLoader = new MemoLoader({memos: "' . $url . '", limit: ' . $perPage . ', domId: "' . $dom . '"});});</script>';
    }

    /**
     * 升级插件时的操作(激活时补全缺失列,兼容老版本升级)
     * @param Db $db 数据库连接对象
     */
    public static function upgrade(Db $db): void {
        // 获取当前数据库的适配器类型
        $adapterName = self::getAdapterName($db);
        // 待补全的列:表名(不带前缀) => [列名 => 列类型定义]
        $columns = [
            // 说说表:发布位置(2.0 新增)
            'says' => [
                'position' => 'VARCHAR(255) DEFAULT NULL',
            ],
            // 附件表:缩图地址(2.1 新增,无缩图时与 url 保持一致)
            'says_attachment' => [
                'thumb' => 'TEXT DEFAULT NULL',
            ],
        ];
        // 逐表检查补全
        foreach ($columns as $table => $cols) {
            // 完整表名
            $tableName = $db->getPrefix() . $table;
            // 获取现有的列名
            $existingColumns = self::getTableColumns($db, $adapterName, $tableName);
            // 逐列判断,不存在则添加
            foreach ($cols as $column => $definition) {
                // 已存在跳过
                if (in_array($column, $existingColumns)) continue;
                // 不同数据库的补列语句写法不同
                if ($adapterName === 'mysql') {
                    $db->query('ALTER TABLE `' . $tableName . '` ADD COLUMN `' . $column . '` ' . $definition);
                } elseif ($adapterName === 'sqlite') {
                    $db->query('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $column . ' ' . $definition);
                } else {
                    $db->query('ALTER TABLE "' . $tableName . '" ADD COLUMN "' . $column . '" ' . $definition);
                }
            }
        }
        // 回填附件缩图字段:老数据没有缩图,先与原图 url 保持一致,保证字段永远有值(前端零回退逻辑)
        // 注:曾试用过又放弃的 large 字段如已存在于个别库中,保留无害,不做删除
        $attachmentTable = $db->getPrefix() . 'says_attachment';
        // 按适配器选择表名引号
        $quoted = $adapterName === 'mysql' ? '`' . $attachmentTable . '`' : ($adapterName === 'pgsql' ? '"' . $attachmentTable . '"' : $attachmentTable);
        // 执行回填(已是新数据的行不受影响)
        $db->query('UPDATE ' . $quoted . ' SET thumb = url WHERE thumb IS NULL');
    }

    /**
     * 获取表的现有列名
     * @param Db $db 数据库连接对象
     * @param string $adapterName 适配器名称
     * @param string $tableName 完整表名(带前缀)
     * @return array 列名列表
     */
    private static function getTableColumns(Db $db, string $adapterName, string $tableName): array {
        // MySQL 用 SHOW COLUMNS
        if ($adapterName === 'mysql') {
            $result = $db->fetchAll('SHOW COLUMNS FROM `' . $tableName . '`');
            // 提取列名
            return array_column($result, 'Field');
        }
        // SQLite 用 PRAGMA table_info
        if ($adapterName === 'sqlite') {
            $result = $db->fetchAll('PRAGMA table_info(`' . $tableName . '`)');
            // 提取列名
            return array_column($result, 'name');
        }
        // PostgreSQL 用 information_schema
        $result = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name='" . $tableName . "'");
        // 提取列名
        return array_column($result, 'column_name');
    }

    /**
     * 运行SQL文件的方法
     * @param Db $db 数据库连接对象
     * @param string $action 执行的动作，'install'或'remove'
     */
    private static function runSql(Db $db, string $action): void {
        try {
            // 读取当前数据库的适配器类型
            $adapterName = self::getAdapterName($db);
            // 根据动作获取对应的SQL文件名
            $sqlfilename = '';
            // 根据动作获取对应的SQL文件名，不能用三元运算符，因为要抛出异常
            if ($action === 'install') {
                // 获取当前目录下 sql 下的安装文件
                $sqlfilename = strtolower($adapterName) . '.sql';
            } elseif ($action === 'remove') {
                // 获取当前目录下 sql 下的删除文件
                $sqlfilename = strtolower($adapterName) . '_remove.sql';
            } else {
                throw new PluginException('未知的动作 - ' . $action);
            }
            // 获取当前目录下 sql 下的删除文件
            $sqlfile = dirname(__FILE__) . '/sql/' . $sqlfilename;
            // 缺文件则直接返回
            if (!file_exists($sqlfile)) throw new PluginException($sqlfile . ' 文件不存在');
            // 读取SQL文件内容并替换前缀
            $sql = file_get_contents($sqlfile);
            // 替换前缀
            $sql = str_replace('{{prefix}}', $db->getPrefix(), $sql);
            // 逐条执行(与安装脚本同一处理逻辑)
            foreach (splitSqlStatements($sql) as $statement)  $db->query($statement);
        } catch (PluginException $e) {
            throw new PluginException('执行SQL文件失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前数据库适配器名称
     * @param Db $db 数据库连接对象
     * @return string 数据库适配器名称
     */
    private static function getAdapterName(Db $db): string {
        // 默认为空
        $adapterName = '';
        // 获取当前数据库的适配器类型
        $adapter = $db->getAdapterName();
        // 判断适配器类型
        if (strpos($adapter, 'Mysql') !== false) {
            $adapterName = 'mysql'; // mysql
        } elseif (strpos($adapter, 'SQLite') !== false) {
            $adapterName = 'sqlite'; // sqlite
        } elseif (strpos($adapter, 'PostgreSQL') !== false || strpos($adapter, 'PgSQL') !== false) {
            $adapterName = 'pgsql'; // postgresql(Typecho 适配器名为 PgSQL / Pdo_PgSQL)
        }
        // 如果没有适配器类型,直接跳过删除,不阻断禁用流程
        if (!$adapterName) throw new PluginException('不支持的数据库类型: ' . $adapter);
        // 返回适配器名称
        return $adapterName;
    }
}