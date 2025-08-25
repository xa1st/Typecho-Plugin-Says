document.addEventListener('DOMContentLoaded', function() {
    const publishBtn = document.getElementById('publish-btn');
    const contentTextarea = document.getElementById('content');
    const messageContainer = document.getElementById('message-container');
    const saysList = document.getElementById('says-list');
    
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
    function showMessage(message, type) {
        messageContainer.textContent = message;
        messageContainer.className = type === 'success' ? 'success-message' : 'error-message';
        messageContainer.style.display = 'block';
        
        // 3秒后自动隐藏
        setTimeout(function() {
            messageContainer.style.display = 'none';
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
                <a href="javascript:;" data-id="${id}" class="operate-delete">删除</a>
            </div>
            <div class="say-content">${content}</div>
            <div class="say-meta">
                <span class="say-date">${formattedDate}</span>
                <span class="say-source">来源: ${source}</span>
                <span class="say-source">说说ID: ${id}</span>
            </div>
        `;
        
        // 检查是否有"暂无说说"的提示，如果有则移除
        const emptyNotice = saysList.querySelector('.notice');
        if (emptyNotice) emptyNotice.remove();
        
        // 添加到列表顶部
        saysList.insertBefore(newSay, saysList.firstChild);
        
        // 为新添加的删除按钮绑定事件
        attachDeleteHandlers();
    }
    
    // 发布说说
    publishBtn.addEventListener('click', function() {
		// 获取输入
        const content = contentTextarea.value.trim();
        // 检查内容
        if (!content) return showMessage('说说内容不能为空', 'error');
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
        fetch(window.location.href, {method: 'POST', body: formData}).then(response => response.json()).then(data => {
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
        }).catch(error => {
            // 恢复按钮状态
            publishBtn.classList.remove('operating');
            publishBtn.textContent = '发布';
            showMessage('请求出错: ' + error.message, 'error');
        });
    });
    
    // 删除说说 - 使用Typecho内置提示框
    function attachDeleteHandlers() {
        document.querySelectorAll('.operate-delete').forEach(function(deleteBtn) {
            // 检查是否已经绑定过事件
            if (!deleteBtn.hasAttribute('data-event-bound')) {
                deleteBtn.setAttribute('data-event-bound', 'true');
                
                deleteBtn.addEventListener('click', function() {
                    const sayId = this.getAttribute('data-id');
                    const sayElement = document.getElementById('say-' + sayId);
                    const self = this;
                    
                    // 使用Typecho内置的确认对话框
                    if (window.Typecho && Typecho.Modal) {
                        Typecho.Modal.confirm('确定要删除这条说说吗？', '删除确认', function(yes) {
                            // 用户确认删除
							if (yes) performDelete(sayId, sayElement, self);
                        });
                    } else {
                        // 降级处理，如果Typecho.Modal不可用
                        if (confirm('确定要删除这条说说吗？')) performDelete(sayId, sayElement, self);
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
        fetch(window.location.href, {method: 'POST', body: formData}).then(response => response.json()).then(data => {
            if (data.status === 1) {
                // 使用Typecho内置的提示框显示成功消息
                if (window.Typecho && Typecho.Message) Typecho.Message.success(data.message || '删除成功');
                // 成功，移除元素
                sayElement.remove();
                // 检查是否还有说说，如果没有则显示\"暂无说说\"
                if (saysList.children.length === 0) saysList.innerHTML = `<div class="typecho-list-table-row notice"><p>暂无说说</p></div>`;
            } else {
                // 恢复按钮状态
               	button.classList.remove('operating');
                button.textContent = '删除';
                
                // 使用Typecho内置的提示框显示错误消息
                if (window.Typecho && Typecho.Message) {
                    Typecho.Message.error(data.message || '删除失败');
                } else {
                    // 降级处理
                    alert(data.message || '删除失败');
                }
            }
        }).catch(error => {
            // 恢复按钮状态
            button.classList.remove('operating');
            button.textContent = '删除';
            // 显示错误
            if (window.Typecho && Typecho.Message) {
                Typecho.Message.error('请求出错: ' + error.message);
            } else {
                alert('请求出错: ' + error.message);
            }
        });
    }
    // 初始化删除按钮事件
    attachDeleteHandlers();
    // 尝试启用编辑器
    if (typeof window.editor !== 'undefined' && document.getElementById('content')) {
        window.editor.init(document.getElementById('content')).then(editor => {
            editor.focus();
        }).catch(error => {
            console.error('编辑器加载失败', error);
        });
    }
});