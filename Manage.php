<?php
namespace TypechoPlugin\Says;

use Typecho\Db;
use Typecho\Widget;
use Widget\Notice;
use Utils\Helper;
use Typecho\Common;
use Typecho\Db\Query;

/**
 * 说说后台管理
 */

if(!defined('__TYPECHO_ADMIN__')) exit;

// 引入公共函数
require_once 'function.php';

// 获取当前请求数据
$request = \Typecho\Request::getInstance();

if ($request->isGet()) {
    include 'header.php';
    include 'menu.php';
}

// 处理操作请求
$db = Db::get();
$user = Widget::widget('Widget_User');
$options = Helper::options();
$pluginOptions = Helper::options()->plugin('Says');
$user->pass('administrator');
$notice = Widget::widget('Widget_Notice');

// 创建表结构检查
try {
    $prefix = $db->getPrefix();
    $tableName = $prefix . 'says';
    $adapterName = get_class($db->getAdapter());
    
    // 检查表是否存在
    if (strpos($adapterName, 'SQLite') !== false) {
        // SQLite
        $tables = $db->fetchAll($db->select('name')
            ->from('sqlite_master')
            ->where("type = 'table' AND name = ?", $tableName));
    } else {
        // MySQL
        $tables = $db->fetchAll($db->query("SHOW TABLES LIKE '{$tableName}'")); // MySQL的LIKE语句不支持参数绑定
    }
    
    if (empty($tables)) {
        $notice->set(_t('说说数据表不存在，请禁用后重新启用插件'), 'error');
        include 'footer.php';
        exit;
    }
} catch (\Exception $e) {
    $notice->set(_t('检查数据表失败：' . $e->getMessage()), 'error');
    include 'footer.php';
    exit;
}

// 处理AJAX请求
if ($request->isPost() && $request->get('ajax') == 1) {
    // 默认的返回串
    $response = ['status'=>0, 'message'=>'未知错误'];
    // 操作
    $act = trim($request->get('do', ''));
    
    // 发布说说
    if ($act == 'publish') {
        // 获取说说内容
        $content = trim($request->get('content', ''));
        // 后台发布:使用当前登录用户作为作者
        $userId = $user->uid;
        // 状态
        $status = (strtoupper($request->get('visibility', 'PUBLIC')) === 'PUBLIC') ? 1 : 0;
        // 入库
        try {
            // 验证内容
            list($valid, $message) = validateSayContent($content);
            // 验证失败直接抛出错误
            if (!$valid) throw new \Exception($message);
            // 获取当前时间
            $currentTime = time();
            // 获取UA
            $agent = $request->getAgent();
            // 发布时的来源:手填值优先,留空则按不显示
            $customSource = trim($request->get('source', ''));
            // 位置:可留空
            $position = trim($request->get('position', ''));
            // 来源
            $source = $customSource !== '' ? $customSource : trim((string)(\Utils\Helper::options()->plugin('Says')->defaultSource ?? '')); // 手填优先,留空取默认来源配置
            // 插入数据库
            $lastInsertId = $db->query($db->insert('table.says')->rows([
                'uuid' => generateUUID(),
                'user_id' => $userId,
                'content' => $content,
                'agent' => $agent,
                'ip' => $request->getIp() ?? '0.0.0.0',
                'source' => $source, // 来源为空就空字符串,不走 UA 识别
                'position' => $position !== '' ? $position : null,
                'status' => $status,
                'created_at' => $currentTime,
                'updated_at' => $currentTime,
            ]));
            // 关联附件(前端传入的附件 id 列表),兼容数组或逗号分隔字符串
            $attachmentIds = $request->get('attachmentIds', '');
            // 统一转为整型 id 数组
            $ids = normalizeAttachmentIds($attachmentIds);
            // 有关联 id 才关联到本条说说
            if (!empty($ids)) {
                \TypechoPlugin\Says\Attachment::relate((int)$lastInsertId, $ids);
            }
            // 解析内容中的 #标签 并关联
            \TypechoPlugin\Says\Hashtag::relate((int)$lastInsertId, \TypechoPlugin\Says\Hashtag::extract($content));
            // 组装 resources(附件图列表,memos 兼容客户端与后台行内缩略图使用)
            $resources = [];
            foreach (\TypechoPlugin\Says\Attachment::getBySay((int)$lastInsertId) as $att) {
                $resources[] = [
                    'id' => (int)$att['id'],
                    'name' => 'resources/' . (int)$att['id'],
                    'filename' => $att['name'],
                    'type' => $att['mime'],
                    'size' => (int)$att['size'],
                    'url' => $att['url'],
                    'thumb' => !empty($att['thumb']) ? $att['thumb'] : $att['url'],
                    'externalLink' => $att['url'],
                    'douban' => (int)!empty($att['douban']),
                ];
            }
            $response = ['status' => 1, 'message' => '发布成功', 'data' => [
                'id' => $lastInsertId,
                'source' => $source,
                'position' => $position !== '' ? $position : '',
                'resources' => $resources,
            ]];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '发布失败: ' . $e->getMessage()];
        }
    }

    // 独立上传附件(暂存,num=0)
    if ($act == 'upload_attachment') {
        // 默认返回
        $response = ['status' => 0, 'message' => '上传失败'];
        try {
            // 没有上传文件则返回错误
            if (!isset($_FILES['attachments'])) throw new \Exception('未收到上传文件');
            // 是否豆瓣卡片封面(douban=1 时入库标记,前端宫格不展示)
            $isDouban = (int)$request->get('douban', 0) === 1 ? 1 : 0;
            // 重组多文件上传为列表
            $files = [];
            // 单个文件直接包装为数组
            if (!is_array($_FILES['attachments']['name'])) {
                $files[] = $_FILES['attachments'];
            } else {
                // 多文件循环重组
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    $files[] = [
                        'name' => $name,
                        'type' => $_FILES['attachments']['type'][$i],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error' => $_FILES['attachments']['error'][$i],
                        'size' => $_FILES['attachments']['size'][$i],
                    ];
                }
            }
            // 上传结果列表
            $attachments = [];
            // 逐个上传
            foreach ($files as $file) {
                // 上传出错或没有文件则跳过
                if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || empty($file['name'])) continue;
                // 扩展名
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                // 类型不在白名单则跳过
                if (!in_array($ext, getAllowedFileTypes())) continue;
                // 目标路径(按配置的存储路径 + 重命名规则)
                $target = generateTargetPath($file['name']);
                // 上传并暂存(不关联,sayId=0),豆瓣封面带 douban 标记
                try {
                    $att = \TypechoPlugin\Says\Attachment::upload($file['tmp_name'], $target, false, $isDouban);
                    $attachments[] = [
                        'id' => (int)$att['id'],
                        'name' => $att['name'],
                        'url' => $att['url'],
                        'size' => (int)$att['size'],
                        'douban' => (int)!empty($att['douban']),
                    ];
                } catch (\Exception $e) {
                    // 单个附件上传失败不阻断
                }
            }
            // 上传结果
            $response = ['status' => 1, 'message' => '上传成功', 'data' => $attachments];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '上传失败: ' . $e->getMessage()];
        }
    }

    // 删除附件
    if ($act == 'delete_attachment') {
        try {
            // 附件 id
            $attachmentId = intval($request->get('id', 0));
            // 当前编辑的说说 id(已使用时必传)
            $sayId = intval($request->get('sayId', 0));
            // 调用附件删除
            $ok = \TypechoPlugin\Says\Attachment::remove($attachmentId, $sayId > 0 ? $sayId : null);
            // 删除结果
            $response = $ok ? ['status' => 1, 'message' => '删除成功'] : ['status' => 0, 'message' => '删除失败'];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '删除失败: ' . $e->getMessage()];
        }
    }

    // 修改说说
    if ($act == 'edit') {
        $id = intval($request->get('id', 0));
        $content = trim($request->get('content', ''));
        $customTime = trim($request->get('custom_time', ''));
        $customSource = trim($request->get('custom_source', ''));
        $customPosition = trim($request->get('custom_position', ''));
        
        try {
            if ($id <= 0) throw new \Exception('无效的ID');
            
            // 验证内容
            list($valid, $message) = validateSayContent($content);
            if (!$valid) throw new \Exception($message);
            
            // 获取原始数据
            $originalSay = $db->fetchRow($db->select()->from('table.says')->where('id = ?', $id));
            if (!$originalSay) throw new \Exception('说说不存在');
            
            // 处理更新时间
            $updatedAt = time();
            if (!empty($customTime)) {
                // 解析自定义时间
                $customTimestamp = strtotime($customTime);
                if ($customTimestamp !== false) {
                    $updatedAt = $customTimestamp;
                }
            }
            
            // 处理来源:手填值优先,留空则按 UA 重新识别并落库
            $source = $customSource !== '' ? $customSource : trim((string)(\Utils\Helper::options()->plugin('Says')->defaultSource ?? '')); // 手填优先,留空取默认来源配置
            // 处理位置:手填值优先,留空则清空
            $position = $customPosition !== '' ? $customPosition : null;
            
            // 更新数据库
            $db->query($db->update('table.says')->rows([
                'content' => $content,
                'source' => $source,
                'position' => $position,
                'updated_at' => $updatedAt,
            ])->where('id = ?', $id));
            
            // 关联编辑时新上传的附件,兼容数组或逗号分隔字符串
            $attachmentIds = $request->get('attachmentIds', '');
            // 统一转为整型 id 数组
            $ids = normalizeAttachmentIds($attachmentIds);
            // 有关联 id 才关联到本条说说
            if (!empty($ids)) {
                \TypechoPlugin\Says\Attachment::relate($id, $ids);
            }
            // 重建标签:按最新内容重新解析关联
            \TypechoPlugin\Says\Hashtag::relate((int)$id, \TypechoPlugin\Says\Hashtag::extract($content));
            
            // 组装 resources(编辑后附件可能增删,回传最新列表)
            $resources = [];
            foreach (\TypechoPlugin\Says\Attachment::getBySay((int)$id) as $att) {
                $resources[] = [
                    'id' => (int)$att['id'],
                    'name' => 'resources/' . (int)$att['id'],
                    'filename' => $att['name'],
                    'type' => $att['mime'],
                    'size' => (int)$att['size'],
                    'url' => $att['url'],
                    'thumb' => !empty($att['thumb']) ? $att['thumb'] : $att['url'],
                    'externalLink' => $att['url'],
                    'douban' => (int)!empty($att['douban']),
                ];
            }
            $response = ['status' => 1, 'message' => '修改成功', 'data' => [
                'id' => $id,
                'content' => $content,
                'source' => $source,
                'position' => $position,
                'updated_at' => $updatedAt,
                'resources' => $resources,
            ]];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '修改失败: ' . $e->getMessage()];
        }
    }

    // 获取说说详情(用于编辑)
    if ($act == 'get') {
        $id = intval($request->get('id', 0));
        try {
            if ($id <= 0) throw new \Exception('无效的ID');
            
            $say = $db->fetchRow($db->select()->from('table.says')->where('id = ?', $id));
            if (!$say) throw new \Exception('说说不存在');
            
            // 获取该说说的附件列表
            $attachments = \TypechoPlugin\Says\Attachment::getBySay($id);
            // 格式化附件
            $attachmentList = [];
            foreach ($attachments as $att) {
                $attachmentList[] = [
                    'id' => (int)$att['id'],
                    'name' => $att['name'],
                    'url' => $att['url'],
                    'size' => (int)$att['size'],
                    'douban' => (int)!empty($att['douban']),
                ];
            }
            // 附带附件信息
            $response = ['status' => 1, 'data' => $say, 'attachments' => $attachmentList];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '获取失败: ' . $e->getMessage()];
        }
    }

    // 删除说说
    if ($act == 'delete') {
        // 获取主键
        $id = intval($request->get('id', 0));
        try {
            // 如果ID小于等于0,则返回错误
            if ($id <= 0) throw new \Exception('无效的ID');
            // 先清理该说说的附件(删除关联 + 引用计数,归零则删文件)
            \TypechoPlugin\Says\Attachment::deleteBySay($id);
            // 清理该说说的标签关联(反扣 count,孤标签自动清理)
            \TypechoPlugin\Says\Hashtag::deleteBySay($id);
            // 删除数据
            $db->query($db->delete('table.says')->where('id = ?', $id));
            // 删除成功
            $response = ['status' => 1, 'message' => '删除成功'];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '删除失败: ' . $e->getMessage()];
        }
    }
    // 返回结果
    outputJson($response);
    // 下面的内容不再输出
    exit;
}

// 分页参数
$pageSize = 10; // 每页显示10条
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $pageSize;

// 获取总记录数
$total = $db->fetchObject($db->select(array('COUNT(id)' => 'num'))->from('table.says'))->num;
$totalPages = ceil($total / $pageSize);

// 获取当前页的说说列表
$saysList = $db->fetchAll($db->select()->from('table.says')->order('created_at', Db::SORT_DESC)->page($currentPage, $pageSize));

// 批量取当前页说说的附件(一次查询,行内显示缩略图,不再依赖内容中的图片语法)
$pageAttachments = [];
$sayIds = array_map('intval', array_column($saysList, 'id'));
if (!empty($sayIds)) {
    // 附件关联
    $metas = $db->fetchAll($db->select()->from('table.says_attachment_meta')->where('say_id IN ?', $sayIds));
    // 收集去重后的附件 id
    $attIds = array_values(array_unique(array_map(function ($m) { return (int)$m['attachment_id']; }, $metas)));
    // 有附件才查详情
    if (!empty($attIds)) {
        // 附件详情,按 id 索引
        $attRows = [];
        foreach ($db->fetchAll($db->select()->from('table.says_attachment')->where('id IN ?', $attIds)) as $row) {
            $attRows[(int)$row['id']] = $row;
        }
        // 按 say_id 分组
        foreach ($metas as $m) {
            $aid = (int)$m['attachment_id'];
            if (isset($attRows[$aid])) $pageAttachments[(int)$m['say_id']][] = $attRows[$aid];
        }
    }
}

// 生成分页URL
function pageUrl($page) {
    $options = Helper::options();
    return $options->adminUrl . 'extending.php?panel=Says/Manage.php&page=' . $page;
}

// 获取用户ID(后台发布默认使用当前登录用户)
$userId = $user->uid;

// 获取未使用的附件(暂存区,显示在发表框下方)
$unusedAttachments = \TypechoPlugin\Says\Attachment::getUnused(50);
?>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('说说管理'); ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <div id="says-form" class="says-editor">
                    <h3><?php _e('发表新说说'); ?></h3>
                    <div class="says-editor-body">
                        <label for="content" class="sr-only"><?php _e('说说内容'); ?></label>
                        <div class="says-textarea-wrap">
                            <textarea name="content" id="content" class="w-100 mono" style="height: 150px;" placeholder="<?php _e("请填写要说的内容,这里支持markdown语法,还支持一些特殊的标记,但后台可能看不到\n网易云音乐 / B站视频: 直接将链接复制进来\n比如: https://www.bilibili.com/video/BV1u1jsz2E6f\n豆瓣卡片格式:[标题|评分|简介|封面图(可选)](豆瓣链接)\n比如:[七日世界|5.2|简介|//img1.doubanio.com/lpic/s34852318.jpg](https://www.douban.com/game/36161785)"); ?>"></textarea>
                            <div class="says-toolbar">
                                <button type="button" id="pick-image-btn" class="btn btn-xs" title="<?php _e('上传图片'); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                </button>
                                <button type="button" id="douban-btn" class="btn btn-xs" title="<?php _e('插入豆瓣卡片'); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>
                                </button>
                                <input type="file" id="image-input" accept="image/*" multiple style="display: none;" />
                            </div>
                        </div>
                        <!-- 来源 / 位置(发布时可选,留空不显示来源) -->
                        <div class="says-publish-fields">
                            <input type="text" id="publish-source" class="text-s" placeholder="<?php _e('来源(客户端),留空则不显示'); ?>" />
                            <input type="text" id="publish-position" class="text-s" placeholder="<?php _e('位置,可留空'); ?>" />
                        </div>
                        <div id="message-container" style="margin-top: 10px; display: none;"></div>
                            <!-- 未使用附件暂存区 -->
                            <div id="unused-attachments" class="says-unused">
                                <?php if (!empty($unusedAttachments)): ?>
                                    <?php foreach ($unusedAttachments as $att): ?>
                                        <div class="says-unused-item" data-id="<?php echo (int)$att['id']; ?>" data-url="<?php echo htmlspecialchars($att['url']); ?>" data-name="<?php echo htmlspecialchars($att['name']); ?>" data-douban="<?php echo !empty($att['douban']) ? 1 : 0; ?>">
                                            <?php if (!empty($att['douban'])): ?><span class="says-unused-badge">[豆]</span><?php endif; ?>
                                            <span class="says-unused-name" title="<?php echo !empty($att['douban']) ? '点击插入豆瓣卡片' : _t('点击插入到内容'); ?>"><?php echo htmlspecialchars($att['name']); ?></span>
                                            <a href="javascript:;" class="says-unused-eye" title="<?php _e('预览'); ?>">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                            <a href="javascript:;" class="says-unused-del" title="<?php _e('删除'); ?>">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <!-- 待关联附件id(本次新上传,逗号分隔,由JS维护,提交时带回) -->
                            <input type="hidden" id="publish-attachment-ids" value="" />
                            <p class="submit">
                                <span class="right">
                                    <button type="button" id="publish-btn" class="btn primary"><?php _e('发布'); ?></button>
                                </span>
                            </p>
                        </div>
                    </div>
                
                <div id="edit-form" class="says-editor" style="display: none;">
                    <h3><?php _e('编辑说说'); ?></h3>
                    <div class="says-editor-body">
                        <label for="edit-content" class="sr-only"><?php _e('说说内容'); ?></label>
                        <div class="says-textarea-wrap">
                            <textarea name="edit-content" id="edit-content" class="w-100 mono" style="height: 150px;"></textarea>
                            <div class="says-toolbar">
                                <button type="button" id="edit-pick-image-btn" class="btn btn-xs" title="<?php _e('上传图片'); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                </button>
                                <button type="button" id="edit-douban-btn" class="btn btn-xs" title="<?php _e('插入豆瓣卡片'); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>
                                </button>
                            </div>
                        </div>
                        <!-- 该说说的附件(第一行) -->
                        <div class="says-edit-attachments-row">
                            <div id="edit-attachments" class="says-unused"></div>
                        </div>
                        
                        <!-- 时间 / 来源 / 位置(第二行,各占1/3) -->
                        <div class="says-edit-fields">
                            <input type="text" id="edit-time" name="edit-time" class="text-s" placeholder="<?php _e('更新时间,留空则当前时间'); ?>" />
                            <input type="text" id="edit-source" name="edit-source" class="text-s" placeholder="<?php _e('来源(客户端),留空则不显示'); ?>" />
                            <input type="text" id="edit-position" name="edit-position" class="text-s" placeholder="<?php _e('位置,留空则清空'); ?>" />
                        </div>
                        
                        <div id="edit-message-container" style="margin-top: 10px; display: none;"></div>
                        <p class="submit">
                            <span class="left">
                                <button type="button" id="cancel-edit-btn" class="btn btn-warn"><?php _e('取消'); ?></button>
                            </span>
                            <span class="right">
                                <button type="button" id="save-edit-btn" class="btn primary"><?php _e('保存'); ?></button>
                            </span>
                        </p>
                        <input type="hidden" id="edit-say-id" value="" />
                        <!-- 编辑时新上传、待关联的附件id(逗号分隔,由JS维护,保存时带回) -->
                        <input type="hidden" id="edit-attachment-ids" value="" />
                    </div>
                </div>
                
                <div class="typecho-list-operate clearfix"><h3><?php _e('我的说说'); ?></h3></div>
                <div class="typecho-table-wrap">
                    <div class="typecho-list" id="says-list">
                        <?php if (empty($saysList)): ?>
                            <div class="typecho-list-table-row notice">
                                <p><?php _e('暂无说说'); ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($saysList as $say): ?>
                                <div class="typecho-list-table-row say-list" id="say-<?php echo $say['id']; ?>">
                                    <div class="say-operation">
                                        <a href="javascript:;" data-id="<?php echo $say['id']; ?>" class="operate-edit"><?php _e('编辑'); ?></a>
                                        <a href="javascript:;" data-id="<?php echo $say['id']; ?>" class="operate-delete"><?php _e('删除'); ?></a>
                                    </div>
                                    <div class="say-content" data-raw-content="<?php echo htmlspecialchars($say['content']); ?>"></div>
                                    <?php if (!empty($pageAttachments[$say['id']])): ?>
                                    <div class="says-unused say-list-attachments">
                                        <?php foreach ($pageAttachments[$say['id']] as $att): ?>
                                        <div class="says-unused-item" data-full="<?php echo htmlspecialchars($att['url']); ?>" data-douban="<?php echo !empty($att['douban']) ? 1 : 0; ?>">
                                            <?php if (!empty($att['douban'])): ?><span class="says-unused-badge">[豆]</span><?php endif; ?>
                                            <span class="says-unused-name" title="点击放大"><?php echo htmlspecialchars($att['name']); ?></span>
                                            <a href="javascript:;" class="says-unused-eye" title="预览">
                                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </a>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="say-meta" style="margin-top: 10px;border-top: 1px solid #e3e3e3;padding-top: 5px;">
                                        <span class="say-source"><?php _e('ID'); ?>: <?php echo $say['id']; ?></span>
                                        <?php $from = $say['source'] ?? ''; ?>
                                        <span class="say-source"><?php _e('来源'); ?>: <span class="source-text"><?php echo empty($from) ? '本站' : trim($from); ?></span></span>
                                        <?php if (!empty($say['position'])): ?>
                                        <span class="say-source"><?php _e('位置'); ?>: <?php echo htmlspecialchars($say['position']); ?></span>
                                        <?php endif; ?>
                                        <span class="say-date">时间：<?php echo date("Y-m-d H:i:s", $say['updated_at']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <!-- 分页导航 -->
                    <?php if ($totalPages > 1): ?>
                    <div class="typecho-pager" style="float:unset">
                        <div class="typecho-pager-content">
                            <ul>
                                <?php if ($currentPage > 1): ?>
                                <li class="prev"><a href="<?php echo pageUrl($currentPage - 1); ?>"><?php _e('&laquo; 上一页'); ?></a></li>
                                <?php endif; ?>
                          
                                <?php
                                // 计算分页导航显示的起始和结束页码
                                $beginPage = max(1, $currentPage - 3);
                                $endPage = min($totalPages, $beginPage + 6);
                          
                                if ($beginPage > 1) {
                                    echo '<li><a href="' . pageUrl(1) . '">1</a></li>';
                                    if ($beginPage > 2) {
                                        echo '<li><span>...</span></li>';
                                    }
                                }
                          
                                for ($i = $beginPage; $i <= $endPage; $i++) {
                                    if ($i == $currentPage) {
                                        echo '<li class="current"><a href="' . pageUrl($i) . '">' . $i . '</a></li>';
                                    } else {
                                        echo '<li><a href="' . pageUrl($i) . '">' . $i . '</a></li>';
                                    }
                                }
                          
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<li><span>...</span></li>';
                                    }
                                    echo '<li><a href="' . pageUrl($totalPages) . '">' . $totalPages . '</a></li>';
                                }
                                ?>
                          
                                <?php if ($currentPage < $totalPages): ?>
                                <li class="next"><a href="<?php echo pageUrl($currentPage + 1); ?>"><?php _e('下一页 &raquo;'); ?></a></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* 分页条:横排方格样式(后台主题对自定义 pager 结构无样式,显式接管) */
.typecho-pager ul {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
    list-style: none;
    margin: 0;
    padding: 0;
}
.typecho-pager li {
    list-style: none;
}
.typecho-pager a {
    display: block;
    padding: 2px 10px;
    border: 1px solid #e3e3e3;
    background: #fff;
    border-radius: 2px;
}
.typecho-pager li.current a {
    border-color: #467b96;
    color: #467b96;
    font-weight: bold;
}
.typecho-pager {
    margin-top: 10px;
}
.typecho-list-table-row {
    position: relative;
    padding: 16px;
    margin-bottom: 16px;
    border: 1px solid #e3e3e3;
    background: #fff;
    border-radius: 2px;
}
/* 发表/编辑说说卡片(Typecho 1.3.x 已无 typecho-edit-content/column-18 类,改用自有样式) */
.says-editor {
    margin-bottom: 20px;
    padding: 20px;
    border: 1px solid #e3e3e3;
    border-radius: 2px;
    background: #fff;
}
.says-editor > h3 {
    margin: 0 0 14px;
}
.says-editor-body {
    width: 100%;
}
.say-operation {
    position: absolute;
    bottom: 16px;
    right: 16px;
}
.say-operation a {
    margin-left: 10px;
}
.say-content {
    margin-bottom: 10px;
    word-break: break-all;
    /* 后台列表显示原始文本,保留换行 */
    white-space: pre-wrap;
}
.say-content img {
    max-width: 500px;
    height: auto;
}
.say-meta {
    color: #999;
    font-size: 0.92857em;
    /* 给右下角的编辑/删除留出空间,避免长 meta 压到按钮 */
    padding-right: 100px;
}
.say-date, .say-source {
    margin-right: 15px;
}
.submit {
    margin-top: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.submit .left {
    display: flex;
}
.submit .left .text-s{
    width: 100%;
}
.submit .right {
    margin-left: auto;
    text-align: right;
}
.submit .right button{
    min-width: 90px;
}
/* 浮动提示(toast):固定在页面顶部,成功/失败几秒后自动消失 */
.says-toast {
    position: fixed;
    top: 60px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    max-width: 80%;
    padding: 10px 18px;
    border-radius: 4px;
    font-size: 13px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.15);
    transition: opacity 0.3s;
    pointer-events: none;
}
.says-toast-success {
    background-color: #f0f9eb;
    color: #67c23a;
    border: 1px solid #e1f3d8;
}
.says-toast-error {
    background-color: #fef0f0;
    color: #f56c6c;
    border: 1px solid #fde2e2;
}
.says-toast.fade-out {
    opacity: 0;
}
.operating {
    opacity: 0.5;
    pointer-events: none;
}
#edit-form {
    /* 已被 .says-editor 卡片样式覆盖,保留淡灰底以区分编辑态 */
    background: #f9f9f9;
}
/* 图片工具栏:浮动在textarea右下角 */
.says-textarea-wrap {
    position: relative;
}
.says-textarea-wrap textarea {
    padding-bottom: 40px; /* 给右下角按钮留出空间,避免遮挡正在输入的最后一行 */
}
.says-toolbar {
    position: absolute;
    right: 8px;
    bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.says-toolbar .btn.btn-xs {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    padding: 3px 10px;
    line-height: 1.4;
    opacity: 0.85;
    transition: opacity .15s;
}
.says-toolbar .btn.btn-xs:hover {
    opacity: 1;
}
/* 编辑框:附件区(第一行) */
.says-edit-attachments-row {
    margin-top: 8px;
}
.says-edit-attachments-row .says-unused {
    margin-top: 0;
}
/* 发布框:来源 / 位置(各占1/2) */
.says-publish-fields {
    margin-top: 10px;
    display: flex;
    gap: 10px;
}
.says-publish-fields input {
    flex: 1 1 0;
    min-width: 0;
}
/* 编辑框:时间 / 来源 / 位置(各占1/3) */
.says-edit-fields {
    margin-top: 10px;
    display: flex;
    gap: 10px;
}
.says-edit-fields input {
    flex: 1 1 0;
    min-width: 0;
}
/* 未使用附件暂存区 */
.says-unused {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.says-unused-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px;
    background: #fff;
    border: 1px solid #e3e3e3;
    border-radius: 3px;
    font-size: 12px;
    /* 大屏:一行 3 个 */
    width: calc((100% - 12px) / 3);
    box-sizing: border-box;
}
.says-unused-name {
    cursor: pointer;
    color: #467b96;
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* 豆瓣封面标记 */
.says-unused-badge {
    color: #3354AA;
    font-weight: bold;
    flex: 0 0 auto;
}
/* 上传中的占位条:不可交互,虚框呼吸提示 */
.says-unused-item--uploading {
    pointer-events: none;
    background: #fafafa;
    border-style: dashed;
}
.says-unused-item--uploading .says-unused-name {
    color: #999;
    cursor: default;
    animation: says-uploading-pulse 1.2s ease-in-out infinite;
}
@keyframes says-uploading-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .45; }
}
.says-unused-eye, .says-unused-del {
    display: inline-flex;
    align-items: center;
    color: #999;
    text-decoration: none;
}
.says-unused-eye:hover { color: #467b96; }
.says-unused-del:hover { color: #c00; }
.says-unused-empty {
    color: #999;
    font-size: 12px;
}
/* 图片预览模态框 */
.says-modal-mask {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.7);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
.says-modal-mask img {
    max-width: 90%;
    max-height: 90%;
    border-radius: 4px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}
.says-modal-close {
    position: fixed;
    top: 20px;
    right: 30px;
    color: #fff;
    font-size: 32px;
    cursor: pointer;
    z-index: 10000;
    line-height: 1;
}
/* 移动端按钮适配(PC 尺寸不变) */
@media (max-width: 575px) {
    .says-editor .submit {
        flex-wrap: wrap;
        gap: 8px;
    }
    /* 发表新说说:发布按钮占满整行 */
    #says-form .submit .right {
        width: 100%;
        margin-left: 0;
        text-align: center;
    }
    #says-form .submit .right button {
        width: 100%;
        min-width: 0;
    }
    /* 编辑说说:取消/保存各占一半 */
    #edit-form .submit .left,
    #edit-form .submit .right {
        flex: 1 1 0;
        width: 50%;
        margin-left: 0;
        text-align: center;
    }
    #edit-form .submit .left button,
    #edit-form .submit .right button {
        width: 100%;
        min-width: 0;
    }
}
/* 中屏(平板):附件暂存区一行 2 个 */
@media (min-width: 576px) and (max-width: 991px) {
    .says-unused-item {
        width: calc((100% - 6px) / 2);
    }
}
/* 移动端:附件暂存区一行 1 个(占满) */
@media (max-width: 575px) {
    .says-unused-item {
        width: 100%;
    }
}
</style>

<!-- 引入JS文件 -->
<!-- 后台列表显示原始文本,不解析 markdown,不再加载 marked 库 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const publishBtn = document.getElementById('publish-btn');
    const contentTextarea = document.getElementById('content');
    const saysList = document.getElementById('says-list');
    
    // 编辑相关元素
    const editForm = document.getElementById('edit-form');
    const editContentTextarea = document.getElementById('edit-content');
    const editTimeInput = document.getElementById('edit-time');
    const editSourceInput = document.getElementById('edit-source');
    const editPositionInput = document.getElementById('edit-position');
    const saveEditBtn = document.getElementById('save-edit-btn');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const editSayIdInput = document.getElementById('edit-say-id');

    // 解析页面中所有已有的说说内容
    function parseExistingContent() {
        // 一次性处理每个说说项目
        document.querySelectorAll('.typecho-list-table-row.say-list').forEach(function(sayRow) {
            // 后台列表显示原始文本(textContent 天然转义,无 XSS 风险)
            const contentElement = sayRow.querySelector('.say-content[data-raw-content]');
            if (contentElement) {
                const rawContent = contentElement.getAttribute('data-raw-content');
                contentElement.textContent = rawContent;
            }
            // 解析时间
            const dateElement = sayRow.querySelector('.say-date[data-timestamp]');
            if (dateElement) {
                const timestamp = dateElement.getAttribute('data-timestamp');
                const date = new Date(timestamp * 1000);
                dateElement.innerHTML = generateTime(date);
            }
        });
    }

    // 页面加载时解析所有现有内容
    parseExistingContent();

    // 为服务端渲染的列表行附件条绑定放大预览
    bindListAttachmentPreviews();

    // 时间生成
    function generateTime(date) {
        return date.getFullYear() + '-' + 
                String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                String(date.getDate()).padStart(2, '0') + ' ' + 
                String(date.getHours()).padStart(2, '0') + ':' + 
                String(date.getMinutes()).padStart(2, '0') + ':' + 
                String(date.getSeconds()).padStart(2, '0');
    }
    
    // 显示浮动提示(顶部居中 toast,成功/失败几秒后自动消失)
    function showMessage(message, type) {
        // 移除已存在的提示,避免堆积
        const oldToast = document.querySelector('.says-toast');
        if (oldToast) oldToast.remove();

        // 创建浮动提示
        const toast = document.createElement('div');
        toast.className = 'says-toast ' + (type === 'success' ? 'says-toast-success' : 'says-toast-error');
        toast.textContent = message;
        document.body.appendChild(toast);

        // 几秒后自动淡出消失
        setTimeout(function() {
            toast.classList.add('fade-out');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 2500);
    }

    // 添加说说到列表
    function addSayToList(id, content, source, position, resources) {
        const now = new Date();
        const formattedDate = generateTime(now);

        const newSay = document.createElement('div');
        newSay.className = 'typecho-list-table-row';
        newSay.id = 'say-' + id;
        newSay.innerHTML = `
            <div class="say-operation">
                <a href="javascript:;" data-id="${id}" class="operate-edit">编辑</a>
                <a href="javascript:;" data-id="${id}" class="operate-delete">删除</a>
            </div>
            <div class="say-content" data-raw-content="${content.replace(/"/g, '&quot;')}"></div>
            <div class="say-meta" style="margin-top: 10px;">
                <span class="say-date" data-timestamp="${Math.floor(Date.now()/1000)}">发布时间：${formattedDate}</span>
                <span class="say-source">来源: <span class="source-text">${source}</span></span>
                ${position ? `<span class="say-source">位置: ${position}</span>` : ''}
                <span class="say-source">说说ID: ${id}</span>
            </div>
        `;

        // 填入原始文本(textContent 天然转义,无 XSS 风险)
        newSay.querySelector('.say-content').textContent = content;
        // 填入附件条(图片与正文分离,由 resources 渲染,点击放大)
        renderSayAttachments(newSay, Array.isArray(resources) ? resources : []);

        // 检查是否有"暂无说说"的提示，如果有则移除
        const emptyNotice = saysList.querySelector('.notice');
        if (emptyNotice) {
            emptyNotice.remove();
        }
  
        // 添加到列表顶部
        saysList.insertBefore(newSay, saysList.firstChild);
  
        // 为新添加的按钮绑定事件
        attachOperationHandlers();
    }

    // 渲染一条说说的附件条(memos 模式:图片与正文分离;窄条 chips,点击放大,无删除)
    function renderSayAttachments(sayElement, resources) {
        // 移除旧附件条(编辑后可能增删)
        const oldBox = sayElement.querySelector('.say-list-attachments');
        if (oldBox) oldBox.remove();
        const list = Array.isArray(resources) ? resources : [];
        // 无附件不渲染
        if (list.length === 0) return;
        // 构建窄条
        const box = document.createElement('div');
        box.className = 'says-unused say-list-attachments';
        box.innerHTML = list.map(function (att) {
            const full = (att.url || '').replace(/"/g, '&quot;');
            return `
                <div class="says-unused-item" data-full="${full}" data-douban="${att.douban ? 1 : 0}">
                    ${att.douban ? '<span class="says-unused-badge">[豆]</span>' : ''}
                    <span class="says-unused-name" title="点击放大">${att.filename || ''}</span>
                    <a href="javascript:;" class="says-unused-eye" title="预览">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                </div>
            `;
        }).join('');
        // 插到正文之后
        const contentEl = sayElement.querySelector('.say-content');
        if (contentEl) contentEl.insertAdjacentElement('afterend', box);
        else sayElement.appendChild(box);
        // 绑定预览
        bindListAttachmentPreviews(box);
    }

    // 为列表中的附件条绑定放大预览(点文件名或眼睛都放大原图,幂等可重复调用)
    function bindListAttachmentPreviews(scope) {
        (scope || document).querySelectorAll('.say-list-attachments .says-unused-item').forEach(function (item) {
            // 已绑定则跳过
            if (item.hasAttribute('data-eye-bound')) return;
            item.setAttribute('data-eye-bound', 'true');
            // 预览原图
            const full = item.getAttribute('data-full');
            if (!full) return;
            const open = function () { previewImage(full); };
            item.querySelector('.says-unused-eye').addEventListener('click', open);
            item.querySelector('.says-unused-name').addEventListener('click', open);
        });
    }

    // 更新说说列表项
    function updateSayInList(id, content, updatedAt, source, position, resources) {
        const sayElement = document.getElementById('say-' + id);
        if (sayElement) {
            // 更新内容
            const contentElement = sayElement.querySelector('.say-content');
            contentElement.setAttribute('data-raw-content', content);
            contentElement.textContent = content;

            // 更新附件条(编辑后附件可能增删)
            renderSayAttachments(sayElement, Array.isArray(resources) ? resources : []);
            // 更新时间
            const dateElement = sayElement.querySelector('.say-date');
            dateElement.setAttribute('data-timestamp', updatedAt);
            dateElement.textContent = new Date(updatedAt * 1000).toLocaleString('zh-CN').replace('T', ' ');
            
            // 更新来源
            const sourceElement = sayElement.querySelector('.source-text');
            sourceElement.textContent = source;
            
            // 更新位置:添加或更新位置槽
            let posElement = sayElement.querySelector('.say-position');
            if (position) {
                if (!posElement) {
                    // 在来源后插入位置槽
                    posElement = document.createElement('span');
                    posElement.className = 'say-source say-position';
                    if (sourceElement && sourceElement.closest('.say-meta')) {
                        sourceElement.insertAdjacentElement('afterend', posElement);
                    } else {
                        sayElement.querySelector('.say-meta').appendChild(posElement);
                    }
                }
                posElement.textContent = '位置: ' + position;
            } else if (posElement) {
                posElement.remove();
            }
        }
    }

    // ===== 图片上传与暂存区 =====
    // 待关联附件id的hidden输入框:发布/编辑各自独立,上传成功后把id追加进去,提交时一并带回
    const publishIdsInput = document.getElementById('publish-attachment-ids');
    const editIdsInput = document.getElementById('edit-attachment-ids');
    // 发布/编辑使用的上传按钮和文件框
    const pickImageBtn = document.getElementById('pick-image-btn');
    const imageInput = document.getElementById('image-input');

    // 豆瓣卡片模板插入:在光标处写入 [标题|评分|简介|封面图url](豆瓣页面地址)
    // 前台 says.js 会把豆瓣链接渲染成卡片;传入 coverUrl 时用作封面地址
    function insertDoubanTemplate(textarea, coverUrl) {
        if (!textarea) return;
        // 封面地址:用刚上传的图/附件条对应图,没有则保留占位
        const cover = coverUrl || '封面图url';
        const tpl = '[标题|评分|简介|' + cover + '](https://movie.douban.com/subject/)';
        // 光标位置(取不到则追加到末尾)
        const start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
        const end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
        // 替换选区/插入模板
        textarea.value = textarea.value.slice(0, start) + tpl + textarea.value.slice(end);
        // 聚焦并把光标放到模板末尾,方便直接填写
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + tpl.length;
    }
    // 发布框:豆瓣按钮(选择封面图上传,上传成功后插入豆瓣卡片模板)
    const doubanBtn = document.getElementById('douban-btn');
    if (doubanBtn) {
        // 隐藏的封面文件选择框
        const doubanInput = document.createElement('input');
        doubanInput.type = 'file';
        doubanInput.accept = 'image/*';
        doubanInput.style.display = 'none';
        document.body.appendChild(doubanInput);
        // 点按钮打开选图
        doubanBtn.addEventListener('click', function() {
            doubanInput.click();
        });
        // 选好图就按豆瓣封面上传
        doubanInput.addEventListener('change', function() {
            const unusedContainer = document.getElementById('unused-attachments');
            uploadFiles(this.files, unusedContainer, true);
            this.value = '';
        });
    }

    // 从hidden输入框读出id数组
    function getHiddenIds(input) {
        return input && input.value ? input.value.split(',').filter(Boolean).map(Number) : [];
    }
    // 写回hidden输入框(去重)
    function setHiddenIds(input, ids) {
        if (input) input.value = Array.from(new Set(ids)).join(',');
    }
    // 追加一个id
    function addHiddenId(input, id) {
        const ids = getHiddenIds(input);
        if (ids.indexOf(id) === -1) ids.push(id);
        setHiddenIds(input, ids);
    }
    // 移除一个id
    function removeHiddenId(input, id) {
        const ids = getHiddenIds(input);
        const idx = ids.indexOf(id);
        if (idx !== -1) ids.splice(idx, 1);
        setHiddenIds(input, ids);
    }

    /**
     * 图片预览模态框
     */
    function previewImage(url) {
        // 移除已存在的模态框
        const oldMask = document.querySelector('.says-modal-mask');
        if (oldMask) oldMask.remove();
        // 创建遮罩
        const mask = document.createElement('div');
        mask.className = 'says-modal-mask';
        // 创建图片
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        // 点击遮罩关闭
        mask.addEventListener('click', function() { mask.remove(); });
        // 加入页面
        mask.appendChild(img);
        document.body.appendChild(mask);
    }

    // ===== 上传中占位与发布锁 =====
    // 在途上传计数:>0 时禁止发布/保存,避免"图还在传就提交"导致附件丢失
    let uploadingCount = 0;

    /**
     * 往容器追加一个"上传中"占位条(不可点击/预览/删除,成功后原地替换)
     * @param {HTMLElement} container 容器
     * @returns {HTMLElement} 占位条元素
     */
    function appendUploadingPlaceholder(container) {
        const item = document.createElement('div');
        item.className = 'says-unused-item says-unused-item--uploading';
        item.innerHTML = '<span class="says-unused-name">图片上传中…</span>';
        container.appendChild(item);
        return item;
    }

    /**
     * 移除占位条
     * @param {Array} list 占位条元素列表
     */
    function removeUploadingPlaceholders(list) {
        (list || []).forEach(function (item) {
            if (item && item.parentNode) item.parentNode.removeChild(item);
        });
    }

    /**
     * 通用上传函数:把选中的文件上传到暂存区
     * @param {FileList} files 文件列表
     * @param {HTMLElement} container 暂存区容器
     * @param {boolean} doubanMode 是否豆瓣封面(入库标记 douban=1,成功后插入豆瓣卡片模板)
     */
    function uploadFiles(files, container, doubanMode) {
        // 没有文件则返回
        if (!files || files.length === 0) return;
        // 占位条:每张图一个"上传中"条目(上传期间不可点击/预览/删除)
        const placeholders = [];
        for (let i = 0; i < files.length; i++) placeholders.push(appendUploadingPlaceholder(container));
        // 在途上传计数 +1
        uploadingCount++;
        // 构建 FormData
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'upload_attachment');
        // 豆瓣封面标记
        if (doubanMode) formData.append('douban', 1);
        // 逐文件加入
        for (let i = 0; i < files.length; i++) {
            formData.append('attachments', files[i]);
        }
        // 发送请求
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // 移除占位条(成功则按返回顺序原地替换为真实条目)
            removeUploadingPlaceholders(placeholders);
            if (data.status === 1 && data.data && data.data.length > 0) {
                // 逐个渲染到暂存区
                data.data.forEach(function(att) {
                    // 暂存本次新上传的附件id,发布时一并关联
                    addHiddenId(publishIdsInput, att.id);
                    // 豆瓣封面:把卡片模板写进内容框(封面用刚上传的图)
                    if (doubanMode) insertDoubanTemplate(contentTextarea, att.url);
                    // 渲染暂存区条目(豆瓣封面带 [豆] 标记)
                    appendUnusedItem(container, att.id, att.name, att.url, false, att.douban ? 1 : 0);
                });
                showMessage('上传成功', 'success');
            } else {
                showMessage(data.message || '上传失败', 'error');
            }
        })
        .catch(error => {
            // 失败:移除占位条并提示
            removeUploadingPlaceholders(placeholders);
            showMessage('请求出错: ' + error.message, 'error');
        })
        .finally(function () {
            // 在途计数 -1
            uploadingCount--;
        });
    }

    /**
     * 往暂存区容器追加一个条目
     * @param {HTMLElement} container 容器
     * @param {number} id 附件 id
     * @param {string} name 文件名
     * @param {string} url 访问地址
     * @param {boolean} fromServer 是否来自服务端数据(来自服务端即已属暂存区,不需加入 selected)
     * @param {number} douban 是否豆瓣封面图(带 [豆] 标记,点击插入豆瓣卡片模板)
     */
    function appendUnusedItem(container, id, name, url, fromServer, douban) {
        // 构建条目
        const item = document.createElement('div');
        item.className = 'says-unused-item';
        item.setAttribute('data-id', id);
        item.setAttribute('data-url', url);
        item.setAttribute('data-name', name);
        item.setAttribute('data-douban', douban ? '1' : '0');
        item.innerHTML = `
            ${douban ? '<span class="says-unused-badge">[豆]</span>' : ''}
            <span class="says-unused-name" title="${douban ? '点击插入豆瓣卡片' : '点击预览'}">${name}</span>
            <a href="javascript:;" class="says-unused-eye" title="预览">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="javascript:;" class="says-unused-del" title="删除">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </a>
        `;
        // 绑定:点名(豆瓣封面插入卡片模板,普通图预览)
        item.querySelector('.says-unused-name').addEventListener('click', function() {
            if (douban) insertDoubanTemplate(contentTextarea, url);
            else previewImage(url);
        });
        // 绑定:眼睛预览
        item.querySelector('.says-unused-eye').addEventListener('click', function() {
            previewImage(url);
        });
        // 绑定:X 删除
        item.querySelector('.says-unused-del').addEventListener('click', function() {
            deleteAttachment(id, item);
        });
        // 追加到容器
        container.appendChild(item);
    }

    /**
     * 删除附件
     * @param {number} id 附件 id
     * @param {HTMLElement} item 对应的 DOM 条目
     */
    function deleteAttachment(id, item) {
        // 确认删除
        if (!confirm('确定删除这个附件吗?')) return;
        // FormData
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'delete_attachment');
        formData.append('id', id);
        // 发送请求
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 1) {
                // 从发布框hidden输入框移除对应id
                removeHiddenId(publishIdsInput, id);
                // 移除 DOM
                if (item) item.remove();
                showMessage('删除成功', 'success');
            } else {
                showMessage(data.message || '删除失败', 'error');
            }
        })
        .catch(error => {
            showMessage('请求出错: ' + error.message, 'error');
        });
    }

    // 发布框:点图片按钮选择文件
    if (pickImageBtn && imageInput) {
        pickImageBtn.addEventListener('click', function() {
            imageInput.click();
        });
        // 选择文件后上传
        imageInput.addEventListener('change', function() {
            const unusedContainer = document.getElementById('unused-attachments');
            uploadFiles(this.files, unusedContainer);
            // 清空值,允许再次选同一文件
            this.value = '';
        });
    }

    // 为已经很早渲染的暂存条目绑定事件
    function bindExistingUnused() {
        document.querySelectorAll('#unused-attachments .says-unused-item').forEach(function(item) {
            // 已绑定则跳过
            if (item.hasAttribute('data-bound')) return;
            item.setAttribute('data-bound', 'true');
            // 读取数据
            const url = item.getAttribute('data-url');
            const id = parseInt(item.getAttribute('data-id'), 10);
            // 是否豆瓣封面(点击插入卡片模板而非预览)
            const isDouban = item.getAttribute('data-douban') === '1';
            // 点文件名:豆瓣封面插入卡片模板,普通图预览
            item.querySelector('.says-unused-name').addEventListener('click', function() {
                if (isDouban) insertDoubanTemplate(contentTextarea, url);
                else previewImage(url);
                // 登记到待关联列表,发布时一并提交(服务端渲染的旧暂存条目
                // 不经过 uploadFiles,这里必须手动登记,否则发布时不会提交该id)
                addHiddenId(publishIdsInput, id);
            });
            // 眼睛预览
            item.querySelector('.says-unused-eye').addEventListener('click', function() {
                previewImage(url);
            });
            // X 删除
            item.querySelector('.says-unused-del').addEventListener('click', function() {
                deleteAttachment(id, item);
            });
        });
    }

    // 初始化暂存区事件
    bindExistingUnused();

    /**
     * 附件已关联到说说后,从「发布框下方暂存区」移除对应条目并清空待关联列表
     * @param {Array} ids 已关联的附件 id 列表
     */
    function clearStagedAttachments(ids) {
        const list = ids || [];
        // 逐个移除发布框暂存区中对应 id 的条目(已关联到说说,不再留在暂存区)
        list.forEach(function(id) {
            document.querySelectorAll('#unused-attachments .says-unused-item[data-id="' + id + '"]').forEach(function(item) {
                item.remove();
            });
        });
        // 清空发布框 hidden 输入框中的待关联 id
        publishIdsInput.value = '';
    }

    // 发布说说
    publishBtn.addEventListener('click', function() {
        // 图片还在上传:禁止发布,避免附件丢失
        if (uploadingCount > 0) {
            showMessage('图片上传中,请稍候', 'error');
            return;
        }
        const content = contentTextarea.value.trim();
  
        if (!content) {
            showMessage('说说内容不能为空', 'error');
            return;
        }
  
        // 禁用按钮，显示加载状态
        publishBtn.classList.add('operating');
        publishBtn.textContent = '发布中...';
  
        // 准备表单数据
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'publish');
        formData.append('content', content);
        formData.append('userId', '<?php echo $userId; ?>');
        // 发布时的来源/位置(可选)
        const publishSourceInput = document.getElementById('publish-source');
        const publishPositionInput = document.getElementById('publish-position');
        const publishSource = publishSourceInput ? publishSourceInput.value.trim() : '';
        const publishPosition = publishPositionInput ? publishPositionInput.value.trim() : '';
        if (publishSource) formData.append('source', publishSource);
        if (publishPosition) formData.append('position', publishPosition);
        // 附带待关联的附件 id(本地上传暂存区,从hidden输入框读取)
        const selectedAttachmentIds = getHiddenIds(publishIdsInput);
        if (selectedAttachmentIds.length > 0) {
            formData.append('attachmentIds', selectedAttachmentIds.join(','));
        }
  
        // AJAX请求
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // 恢复按钮状态
            publishBtn.classList.remove('operating');
            publishBtn.textContent = '发布';
      
            if (data.status === 1) {
                // 成功
                showMessage('发布成功', 'success');
                // 清空输入框
                contentTextarea.value = '';
                // 已关联的附件从暂存区移除(避免一直挂在发布框下方)
                clearStagedAttachments(selectedAttachmentIds);
                // 添加到列表
                addSayToList(data.data.id, content, data.data.source, data.data.position || '', data.data.resources || []);
            } else {
                // 失败
                showMessage(data.message || '发布失败', 'error');
            }
        })
        .catch(error => {
            // 恢复按钮状态
            publishBtn.classList.remove('operating');
            publishBtn.textContent = '发布';
            showMessage('请求出错: ' + error.message, 'error');
        });
    });

    /**
     * 往编辑框附件区追加一个条目(该说说的已有附件)
     * @param {HTMLElement} container 容器
     * @param {number} id 附件 id
     * @param {string} name 文件名
     * @param {string} url 访问地址
     */
    function appendEditAttachmentItem(container, id, name, url, douban) {
        // 构建条目
        const item = document.createElement('div');
        item.className = 'says-unused-item';
        item.setAttribute('data-id', id);
        item.setAttribute('data-url', url);
        item.setAttribute('data-name', name);
        item.setAttribute('data-douban', douban ? '1' : '0');
        item.innerHTML = `
            ${douban ? '<span class="says-unused-badge">[豆]</span>' : ''}
            <span class="says-unused-name" title="${douban ? '点击插入豆瓣卡片' : '点击预览'}">${name}</span>
            <a href="javascript:;" class="says-unused-eye" title="预览">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </a>
            <a href="javascript:;" class="says-unused-del" title="解除关联">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </a>
        `;
        // 点文件名:豆瓣封面往编辑框插入卡片模板,普通图预览
        item.querySelector('.says-unused-name').addEventListener('click', function() {
            if (douban) insertDoubanTemplate(editContentTextarea, url);
            else previewImage(url);
        });
        // 眼睛预览
        item.querySelector('.says-unused-eye').addEventListener('click', function() {
            previewImage(url);
        });
        // X:解除当前说说的关联(编辑中)
        item.querySelector('.says-unused-del').addEventListener('click', function() {
            const sayId = editSayIdInput.value;
            deleteEditAttachment(id, parseInt(sayId, 10), item);
        });
        // 追加到容器
        container.appendChild(item);
    }

    /**
     * 删除编辑中的附件(解除与当前说说的关联)
     * @param {number} id 附件 id
     * @param {number} sayId 当前编辑的说说 id
     * @param {HTMLElement} item DOM 条目
     */
    function deleteEditAttachment(id, sayId, item) {
        // 确认
        if (!confirm('确定移除这个附件吗?')) return;
        // FormData
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'delete_attachment');
        formData.append('id', id);
        formData.append('sayId', sayId > 0 ? sayId : 0);
        // 发送
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 1) {
                // 如果删除的是本次编辑刚上传的附件(尚未保存),同步从编辑框hidden输入框移除,
                // 避免保存时携带已被删除的附件id
                removeHiddenId(editIdsInput, id);
                if (item) item.remove();
                showMessage('已移除', 'success');
            } else {
                showMessage(data.message || '移除失败', 'error');
            }
        })
        .catch(error => {
            showMessage('请求出错: ' + error.message, 'error');
        });
    }

    // 编辑框:豆瓣按钮
    const editDoubanBtn = document.getElementById('edit-douban-btn');
    if (editDoubanBtn) {
        // 隐藏的封面文件选择框
        const editDoubanInput = document.createElement('input');
        editDoubanInput.type = 'file';
        editDoubanInput.accept = 'image/*';
        editDoubanInput.style.display = 'none';
        document.body.appendChild(editDoubanInput);
        // 点按钮打开选图
        editDoubanBtn.addEventListener('click', function() {
            editDoubanInput.click();
        });
        // 选好图就按豆瓣封面上传
        editDoubanInput.addEventListener('change', function() {
            const editAttContainer = document.getElementById('edit-attachments');
            uploadFilesEdit(this.files, editAttContainer, true);
            this.value = '';
        });
    }

    // 编辑框:图片上传按钮
    const editPickImageBtn = document.getElementById('edit-pick-image-btn');
    const editImageInput = document.getElementById('edit-image-input');
    if (editPickImageBtn) {
        editPickImageBtn.addEventListener('click', function() {
            // 若输入框不存在则动态创建
            if (!editImageInput) {
                const input = document.createElement('input');
                input.type = 'file';
                input.id = 'edit-image-input';
                input.accept = 'image/*';
                input.multiple = false;
                input.style.display = 'none';
                document.body.appendChild(input);
                input.addEventListener('change', function() {
                    // 上传到暂存区
                    const editAttContainer = document.getElementById('edit-attachments');
                    uploadFilesEdit(this.files, editAttContainer);
                    this.value = '';
                });
                input.click();
            } else {
                editImageInput.click();
            }
        });
        // 已存在的编辑上传输入框
        if (editImageInput) {
            editImageInput.addEventListener('change', function() {
                const editAttContainer = document.getElementById('edit-attachments');
                uploadFilesEdit(this.files, editAttContainer);
                this.value = '';
            });
        }
    }

    /**
     * 编辑时上传文件:上传到暂存区 + 渲染条目(豆瓣封面追加卡片模板)
     * @param {FileList} files 文件列表
     * @param {HTMLElement} container 附件区容器
     * @param {boolean} doubanMode 是否豆瓣封面(入库标记 douban=1,成功后插入豆瓣卡片模板)
     */
    function uploadFilesEdit(files, container, doubanMode) {
        // 没有文件则返回
        if (!files || files.length === 0) return;
        // 占位条:每张图一个"上传中"条目(上传期间不可点击/预览/删除)
        const placeholders = [];
        for (let i = 0; i < files.length; i++) placeholders.push(appendUploadingPlaceholder(container));
        // 在途上传计数 +1
        uploadingCount++;
        // FormData
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'upload_attachment');
        // 豆瓣封面标记
        if (doubanMode) formData.append('douban', 1);
        for (let i = 0; i < files.length; i++) {
            formData.append('attachments', files[i]);
        }
        // 发送
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // 移除占位条(成功则按返回顺序原地替换为真实条目)
            removeUploadingPlaceholders(placeholders);
            if (data.status === 1 && data.data && data.data.length > 0) {
                data.data.forEach(function(att) {
                    // 暂存本次新上传的附件id,保存编辑时一并关联
                    addHiddenId(editIdsInput, att.id);
                    // 豆瓣封面:把卡片模板写进编辑框(封面用刚上传的图)
                    if (doubanMode) insertDoubanTemplate(editContentTextarea, att.url);
                    // 渲染条目(豆瓣封面带 [豆] 标记)
                    appendEditAttachmentItem(container, att.id, att.name, att.url, att.douban ? 1 : 0);
                });
                showMessage('上传成功', 'success');
            } else {
                showMessage(data.message || '上传失败', 'error');
            }
        })
        .catch(error => {
            // 失败:移除占位条并提示
            removeUploadingPlaceholders(placeholders);
            showMessage('请求出错: ' + error.message, 'error');
        })
        .finally(function () {
            // 在途计数 -1
            uploadingCount--;
        });
    }

    // 当前正在就地编辑的说说 id(用于恢复原卡片),null 表示未在编辑
    let editingSayId = null;

    // 关闭编辑表单并恢复原卡片的显示
    function closeEditForm() {
        // 隐藏编辑表单
        editForm.style.display = 'none';
        // 恢复之前隐藏的原卡片
        if (editingSayId) {
            const sayElement = document.getElementById('say-' + editingSayId);
            if (sayElement) sayElement.style.display = '';
            editingSayId = null;
        }
    }

    // 编辑说说
    function editSay(sayId) {
        // 如果正在编辑其他说说,先关闭并恢复其卡片
        if (editingSayId && editingSayId != sayId) {
            closeEditForm();
        }
        // 先获取说说数据
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'get');
        formData.append('id', sayId);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 1) {
                const say = data.data;
                // 填充编辑表单
                editContentTextarea.value = say.content;
                editTimeInput.value = new Date(say.updated_at * 1000).toLocaleString('zh-CN').replace('T', ' ');
                // 来源:预填库中现有值(可能因历史数据为空)
                editSourceInput.value = say.source || '';
                // 位置:预填库中现有值
                editPositionInput.value = say.position || '';
                editSayIdInput.value = sayId;
                
                // 清空编辑框hidden输入框(编辑时新上传的附件从空开始)
                editIdsInput.value = '';
                
                // 渲染该说说的已有附件
                const editAttContainer = document.getElementById('edit-attachments');
                // 清空旧的
                editAttContainer.innerHTML = '';
                // 服务器返回的附件列表
                const atts = data.attachments || [];
                // 逐个渲染
                atts.forEach(function(att) {
                    // 回填时带上 douban 标记,豆瓣封面继续显示 [豆]
                    appendEditAttachmentItem(editAttContainer, att.id, att.name, att.url, att.douban ? 1 : 0);
                });
                
                // 就地覆盖:隐藏目标卡片,把编辑表单移到它后面原位展开
                const sayElement = document.getElementById('say-' + sayId);
                if (sayElement) {
                    // 隐藏原卡片
                    sayElement.style.display = 'none';
                    // 编辑表单紧跟其卡片后(原位)
                    sayElement.insertAdjacentElement('afterend', editForm);
                }
                // 记录正在编辑的说说 id
                editingSayId = sayId;
                // 显示编辑表单
                editForm.style.display = 'block';
                // A:改为一次性滚动,不用 smooth。smooth 动画期间页面高度刚变大,
                // 边滚动边重排会互相拉扯造成抖动
                editForm.scrollIntoView({ behavior: 'auto', block: 'nearest' });
                // B:focus 延后执行,避免在滚动过程中再抢滚动控制权导致二次抖动
                setTimeout(function() {
                    editContentTextarea.focus();
                }, 100);
            } else {
                showMessage(data.message || '获取说说失败', 'error');
            }
        })
        .catch(error => {
            showMessage('请求出错: ' + error.message, 'error');
        });
    }

    // 保存编辑
    saveEditBtn.addEventListener('click', function() {
        // 图片还在上传:禁止保存,避免附件丢失
        if (uploadingCount > 0) {
            showMessage('图片上传中,请稍候', 'error');
            return;
        }
        const content = editContentTextarea.value.trim();
        const customTime = editTimeInput.value.trim();
        const customSource = editSourceInput.value.trim();
        const customPosition = editPositionInput.value.trim();
        const sayId = editSayIdInput.value;
        
        if (!content) {
            showMessage('说说内容不能为空', 'error');
            return;
        }

        // 时间可留空(留空表示服务端用当前时间);填写时校验格式
        var utcTimeStr = '';
        if (customTime) {
            // 这里判定一下时间格式
            const localDate = new Date(customTime); 
            if (isNaN(localDate.getTime())) {
                showMessage('时间格式不正确', 'error');
                return;
            }
            // 转换为 UTC 时间字符串(格式:YYYY-MM-DDTHH:MM:SSZ,Z 表示 UTC)
            utcTimeStr = localDate.toISOString();
        }
        
        // 禁用按钮
        saveEditBtn.classList.add('operating');
        saveEditBtn.textContent = '保存中...';
        
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'edit');
        formData.append('id', sayId);
        formData.append('content', content);
        formData.append('custom_time', utcTimeStr);
        formData.append('custom_source', customSource);
        formData.append('custom_position', customPosition);
        // 附带编辑时新上传的附件 id(从编辑框hidden输入框读取)
        const editUploadedIds = getHiddenIds(editIdsInput);
        if (editUploadedIds.length > 0) {
            formData.append('attachmentIds', editUploadedIds.join(','));
        }
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // 恢复按钮状态
            saveEditBtn.classList.remove('operating');
            saveEditBtn.textContent = '保存';
            
            if (data.status === 1) {
                showMessage('修改成功', 'success');
                // 更新列表中的项目
                updateSayInList(sayId, content, data.data.updated_at, data.data.source, data.data.position || '', data.data.resources || []);
                // 编辑框已关联的附件:清空hidden输入框(编辑区条目随表单隐藏,下次打开重新渲染)
                editIdsInput.value = '';
                // 隐藏编辑表单并恢复原卡片
                setTimeout(() => {
                    closeEditForm();
                }, 1000);
            } else {
                showMessage(data.message || '修改失败', 'error');
            }
        })
        .catch(error => {
            saveEditBtn.classList.remove('operating');
            saveEditBtn.textContent = '保存';
            showMessage('请求出错: ' + error.message, 'error');
        });
    });

    // 取消编辑
    cancelEditBtn.addEventListener('click', function() {
        closeEditForm();
    });

    // 删除说说 - 使用原生确认对话框
    function attachOperationHandlers() {
        // 编辑按钮
        document.querySelectorAll('.operate-edit').forEach(function(editBtn) {
            if (!editBtn.hasAttribute('data-event-bound')) {
                editBtn.setAttribute('data-event-bound', 'true');
                editBtn.addEventListener('click', function() {
                    const sayId = this.getAttribute('data-id');
                    editSay(sayId);
                });
            }
        });
        
        // 删除按钮
        document.querySelectorAll('.operate-delete').forEach(function(deleteBtn) {
            if (!deleteBtn.hasAttribute('data-event-bound')) {
                deleteBtn.setAttribute('data-event-bound', 'true');
                deleteBtn.addEventListener('click', function() {
                    const sayId = this.getAttribute('data-id');
                    const sayElement = document.getElementById('say-' + sayId);
                    const self = this;
                    
                    if (confirm('确定要删除这条说说吗？')) {
                        performDelete(sayId, sayElement, self);
                    }
                });
            }
        });
    }

    // 执行删除操作
    function performDelete(sayId, sayElement, button) {
        // 显示删除中状态
        button.classList.add('operating');
        button.textContent = '删除中...';
  
        // 准备表单数据
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'delete');
        formData.append('id', sayId);
  
        // AJAX请求
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 1) {
                // 成功，移除元素
                sayElement.remove();
          
                // 检查是否还有说说，如果没有则显示"暂无说说"
                if (saysList.children.length === 0) {
                    saysList.innerHTML = `
                        <div class="typecho-list-table-row notice">
                            <p>暂无说说</p>
                        </div>
                    `;
                }
                showMessage('删除成功', 'success');
            } else {
                // 恢复按钮状态
                button.classList.remove('operating');
                button.textContent = '删除';
                showMessage(data.message || '删除失败', 'error');
            }
        })
        .catch(error => {
            // 恢复按钮状态
            button.classList.remove('operating');
            button.textContent = '删除';
            showMessage('请求出错: ' + error.message, 'error');
        });
    }

    // 初始化操作按钮事件
    attachOperationHandlers();

    // 尝试启用编辑器
    if (typeof window.editor !== 'undefined' && document.getElementById('content')) {
        window.editor.init(document.getElementById('content'))
            .then(editor => {
                editor.focus();
            })
            .catch(error => {
                console.error('编辑器加载失败', error);
            });
    }
});
</script>

<?php
include 'footer.php';
?>