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
        // 获取用户ID
        $userId = $pluginOptions->userId ? $pluginOptions->userId : $user->uid;
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
            // 插入数据库
            $lastInsertId = $db->query($db->insert('table.says')->rows([
                'uuid' => generateUUID(),
                'user_id' => $userId,
                'content' => $content,
                'agent' => $agent,
                'ip' => $request->getIp() ?? '0.0.0.0',
                'status' => $status,
                'created_at' => $currentTime,
                'updated_at' => $currentTime,
            ]));
            $response = ['status' => 1, 'message' => '发布成功', 'data' => ['id' => $lastInsertId, 'source' => getPlatform($agent)]];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '发布失败: ' . $e->getMessage()];
        }
    }

    // 修改说说
    if ($act == 'edit') {
        $id = intval($request->get('id', 0));
        $content = trim($request->get('content', ''));
        $customTime = trim($request->get('custom_time', ''));
        $customAgent = trim($request->get('custom_agent', ''));
        
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
            
            // 处理 User Agent
            $agent = !empty($customAgent) ? $customAgent : $originalSay['agent'];
            
            // 更新数据库
            $db->query($db->update('table.says')->rows([
                'content' => $content,
                'agent' => $agent,
                'updated_at' => $updatedAt,
            ])->where('id = ?', $id));
            
            $response = ['status' => 1, 'message' => '修改成功', 'data' => [
                'id' => $id,
                'content' => $content,
                'agent' => $agent,
                'updated_at' => $updatedAt,
                'source' => getPlatform($agent)
            ]];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '修改失败: ' . $e->getMessage()];
        }
    }

    // 获取说说详情（用于编辑）
    if ($act == 'get') {
        $id = intval($request->get('id', 0));
        try {
            if ($id <= 0) throw new \Exception('无效的ID');
            
            $say = $db->fetchRow($db->select()->from('table.says')->where('id = ?', $id));
            if (!$say) throw new \Exception('说说不存在');
            
            $response = ['status' => 1, 'data' => $say];
        } catch (\Exception $e) {
            $response = ['status' => 0, 'message' => '获取失败: ' . $e->getMessage()];
        }
    }

    // 删除说说
    if ($act == 'delete') {
        // 获取主键
        $id = intval($request->get('id', 0));
        try {
            // 如果ID小于等于0，则返回错误
            if ($id <= 0) throw new \Exception('无效的ID');
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

// 生成分页URL
function pageUrl($page) {
    $options = Helper::options();
    return $options->adminUrl . 'extending.php?panel=Says/Manage.php&page=' . $page;
}

// 获取用户ID（优先使用插件配置的用户ID）
$userId = isset($pluginOptions->userId) && !empty($pluginOptions->userId) ?  $pluginOptions->userId : $user->uid;
?>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('说说管理'); ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <div class="typecho-list-operate clearfix">
                    <div id="says-form" class="typecho-edit-content">
                        <h3><?php _e('发表新说说'); ?></h3>
                        <div class="column-18 suffix">
                            <label for="content" class="sr-only"><?php _e('说说内容'); ?></label>
                            <textarea name="content" id="content" class="w-100 mono" style="height: 150px;" placeholder="<?php _e("请填写要说的内容，这里支持markdown语法，还支持一些特殊的标记，但后台可能看不到\n网易云音乐 / B站视频: 直接将链接复制进来\n比如: https://www.bilibili.com/video/BV1u1jsz2E6f\n豆瓣卡片格式:[标题|评分|简介|封面图(可选)](豆瓣链接)\n比如：[七日世界|5.2|简介|//img1.doubanio.com/lpic/s34852318.jpg](https://www.douban.com/game/36161785)"); ?>"></textarea>
                            <div id="message-container" style="margin-top: 10px; display: none;"></div>
                            <p class="submit">
                                <span class="right">
                                    <button type="button" id="publish-btn" class="btn primary"><?php _e('发布'); ?></button>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- 编辑说说表单 -->
                <div id="edit-form" class="typecho-edit-content" style="display: none;">
                    <h3><?php _e('编辑说说'); ?></h3>
                    <div class="column-18 suffix">
                        <label for="edit-content" class="sr-only"><?php _e('说说内容'); ?></label>
                        <textarea name="edit-content" id="edit-content" class="w-100 mono" style="height: 150px;"></textarea>
                        
                        <!-- 时间选择器 -->
                        <div style="margin: 10px 0;">
                            <label for="edit-time"><?php _e('更新时间'); ?>:</label>
                            <input type="text" id="edit-time" name="edit-time" class="text-s" style="width: 200px;" placeholder="<?php _e('留空则为当前时间'); ?>" />
                            <span class="description"><?php _e('格式: YYYY-MM-DD HH:MM:SS'); ?></span>
                        </div>
                        
                        <!-- User Agent -->
                        <div style="margin: 10px 0;">
                            <label for="edit-agent"><?php _e('User Agent'); ?>:</label>
                            <input type="text" id="edit-agent" name="edit-agent" class="text-s w-100" placeholder="<?php _e('留空则保持原有值'); ?>" />
                        </div>
                        
                        <div id="edit-message-container" style="margin-top: 10px; display: none;"></div>
                        <p class="submit">
                            <span class="left">
                                <button type="button" id="cancel-edit-btn" class="btn"><?php _e('取消'); ?></button>
                            </span>
                            <span class="right">
                                <button type="button" id="save-edit-btn" class="btn primary"><?php _e('保存'); ?></button>
                            </span>
                        </p>
                        <input type="hidden" id="edit-say-id" value="" />
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
                                <div class="typecho-list-table-row" id="say-<?php echo $say['id']; ?>">
                                    <div class="say-operation">
                                        <a href="javascript:;" data-id="<?php echo $say['id']; ?>" class="operate-edit"><?php _e('编辑'); ?></a>
                                        <a href="javascript:;" data-id="<?php echo $say['id']; ?>" class="operate-delete"><?php _e('删除'); ?></a>
                                    </div>
                                    <div class="say-content" data-raw-content="<?php echo htmlspecialchars($say['content']); ?>"></div>
                                    <div class="say-meta">
                                        <span class="say-date" data-timestamp="<?php echo $say['updated_at']; ?>"><?php echo date('Y-m-d H:i:s', $say['updated_at']); ?></span>
                                        <span class="say-source"><?php _e('来源'); ?>: <span class="source-text"><?php echo getPlatform($say['agent']); ?></span></span>
                                        <span class="say-source"><?php _e('说说ID'); ?>: <?php echo $say['id']; ?></span>
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
.typecho-list-table-row {
    position: relative;
    padding: 16px;
    margin-bottom: 16px;
    border: 1px solid #e3e3e3;
    background: #fff;
    border-radius: 2px;
}
.say-operation {
    position: absolute;
    top: 16px;
    right: 16px;
}
.say-operation a {
    margin-left: 10px;
}
.say-content {
    margin-bottom: 10px;
    word-break: break-all;
}
.say-content img {
    max-width: 500px;
    height: auto;
}
.say-meta {
    color: #999;
    font-size: 0.92857em;
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
    width: 70%;
}
.submit .left .text-s{
    width: 100%;
}
.submit .right {
    margin-left: auto;
    width: 20%;
    text-align: right;
}
.submit .right button{
    width: 100%;
}
#message-container, #edit-message-container {
    padding: 8px 12px;
    border-radius: 4px;
}
.success-message {
    background-color: #f0f9eb;
    color: #67c23a;
    border: 1px solid #e1f3d8;
}
.error-message {
    background-color: #fef0f0;
    color: #f56c6c;
    border: 1px solid #fde2e2;
}
.operating {
    opacity: 0.5;
    pointer-events: none;
}
#edit-form {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    background: #f9f9f9;
}
</style>

<!-- 引入Typecho的时间选择器 -->
<link rel="stylesheet" href="<?php $options->adminStaticUrl('css', 'datetimepicker.css'); ?>">
<script src="<?php $options->adminStaticUrl('js', 'datetimepicker.js'); ?>"></script>

<!-- 引入JS文件 -->
<script src="<?php $options->pluginUrl('Says/static/markdown.min.js'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const publishBtn = document.getElementById('publish-btn');
    const contentTextarea = document.getElementById('content');
    const messageContainer = document.getElementById('message-container');
    const saysList = document.getElementById('says-list');
    
    // 编辑相关元素
    const editForm = document.getElementById('edit-form');
    const editContentTextarea = document.getElementById('edit-content');
    const editTimeInput = document.getElementById('edit-time');
    const editAgentInput = document.getElementById('edit-agent');
    const editMessageContainer = document.getElementById('edit-message-container');
    const saveEditBtn = document.getElementById('save-edit-btn');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const editSayIdInput = document.getElementById('edit-say-id');

    // 初始化时间选择器
    if (typeof DateTimePicker !== 'undefined') {
        new DateTimePicker({
            element: editTimeInput,
            format: 'Y-m-d H:i:s',
            timePicker: true
        });
    }

    // Markdown解析函数
    function parseMarkdown(content) {
        // 检查markdown库是否已加载
        if (typeof marked !== 'undefined') return marked.parse(content);
        // 如果库未加载，返回原始内容
        return content; 
    }

    // 解析页面中所有已有的说说内容
    function parseExistingContent() {
        document.querySelectorAll('.say-content[data-raw-content]').forEach(function(element) {
            const rawContent = element.getAttribute('data-raw-content');
            element.innerHTML = parseMarkdown(rawContent);
        });
    }

    // 页面加载时解析所有现有内容
    parseExistingContent();
    
    // 显示消息
    function showMessage(message, type, container) {
        container = container || messageContainer;
        container.textContent = message;
        container.className = type === 'success' ? 'success-message' : 'error-message';
        container.style.display = 'block';
  
        // 3秒后自动隐藏
        setTimeout(function() {
            container.style.display = 'none';
        }, 3000);
    }

    // 添加说说到列表
    function addSayToList(id, content, source) {
        const now = new Date();
        const formattedDate = now.getFullYear() + '-' + 
                             String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                             String(now.getDate()).padStart(2, '0') + ' ' + 
                             String(now.getHours()).padStart(2, '0') + ':' + 
                             String(now.getMinutes()).padStart(2, '0') + ':' + 
                             String(now.getSeconds()).padStart(2, '0');
  
        const newSay = document.createElement('div');
        newSay.className = 'typecho-list-table-row';
        newSay.id = 'say-' + id;
        newSay.innerHTML = `
            <div class="say-operation">
                <a href="javascript:;" data-id="${id}" class="operate-edit">编辑</a>
                <a href="javascript:;" data-id="${id}" class="operate-delete">删除</a>
            </div>
            <div class="say-content" data-raw-content="${content.replace(/"/g, '&quot;')}">${parseMarkdown(content)}</div>
            <div class="say-meta">
                <span class="say-date" data-timestamp="${Math.floor(Date.now()/1000)}">${formattedDate}</span>
                <span class="say-source">来源: <span class="source-text">${source}</span></span>
                <span class="say-source">说说ID: ${id}</span>
            </div>
        `;
  
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

    // 更新说说列表项
    function updateSayInList(id, content, agent, updatedAt, source) {
        const sayElement = document.getElementById('say-' + id);
        if (sayElement) {
            // 更新内容
            const contentElement = sayElement.querySelector('.say-content');
            contentElement.setAttribute('data-raw-content', content);
            contentElement.innerHTML = parseMarkdown(content);
            
            // 更新时间
            const dateElement = sayElement.querySelector('.say-date');
            dateElement.setAttribute('data-timestamp', updatedAt);
            dateElement.textContent = new Date(updatedAt * 1000).toLocaleString('sv-SE').replace('T', ' ');
            
            // 更新来源
            const sourceElement = sayElement.querySelector('.source-text');
            sourceElement.textContent = source;
        }
    }

    // 发布说说
    publishBtn.addEventListener('click', function() {
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
                // 添加到列表
                addSayToList(data.data.id, content, data.data.source);
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

    // 编辑说说
    function editSay(sayId) {
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
                editTimeInput.value = new Date(say.updated_at * 1000).toLocaleString('sv-SE').replace('T', ' ');
                editAgentInput.value = say.agent || '';
                editSayIdInput.value = sayId;
                
                // 显示编辑表单
                editForm.style.display = 'block';
                editForm.scrollIntoView({ behavior: 'smooth' });
                editContentTextarea.focus();
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
        const content = editContentTextarea.value.trim();
        const customTime = editTimeInput.value.trim();
        const customAgent = editAgentInput.value.trim();
        const sayId = editSayIdInput.value;
        
        if (!content) {
            showMessage('说说内容不能为空', 'error', editMessageContainer);
            return;
        }
        
        // 禁用按钮
        saveEditBtn.classList.add('operating');
        saveEditBtn.textContent = '保存中...';
        
        const formData = new FormData();
        formData.append('ajax', 1);
        formData.append('do', 'edit');
        formData.append('id', sayId);
        formData.append('content', content);
        formData.append('custom_time', customTime);
        formData.append('custom_agent', customAgent);
        
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
                showMessage('修改成功', 'success', editMessageContainer);
                // 更新列表中的项目
                updateSayInList(sayId, content, data.data.agent, data.data.updated_at, data.data.source);
                // 隐藏编辑表单
                setTimeout(() => {
                    editForm.style.display = 'none';
                }, 1000);
            } else {
                showMessage(data.message || '修改失败', 'error', editMessageContainer);
            }
        })
        .catch(error => {
            saveEditBtn.classList.remove('operating');
            saveEditBtn.textContent = '保存';
            showMessage('请求出错: ' + error.message, 'error', editMessageContainer);
        });
    });

    // 取消编辑
    cancelEditBtn.addEventListener('click', function() {
        editForm.style.display = 'none';
        editMessageContainer.style.display = 'none';
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