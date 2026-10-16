/**
 * 说说加载器类
 * 专用于says.html页面，加载并渲染说说内容
 */
class MemoLoader {
    /**
     * 构造函数
     * @param {Object} config - 初始化配置对象
     */
    constructor(config) {
        // 配置参数
        this.config = {
            memos: config.memos || "/memos/",  // 接口地址
            limit: config.limit || 8,  // 每页条数
            domId: config.domId || "#says", // 容器id
            ...config
        };
        // 状态参数
        this.state = {currentPage: 1, isLoading: false, hasMore: true };
        // DOM元素
        this.container = null;
        this.loadButton = null;
        // 初始化
        this.init();
    }

    /**
     * 初始化方法
     */
    init() {
        // 获取容器
        this.container = document.querySelector(this.config.domId);
        // 判定容器元素是否存在
        if (!this.container) throw new Error(`指定的容器 ${this.config.domId} 不存在`);
        // 显示加载动画
        this.showLoader();
        // 初始化marked扩展
        this.initMarkedExtensions();
        // 创建加载更多按钮
        this.createLoadButton();
        // 注册全局标签点击回调(供渲染出的标签链接调用)
        const self = this;
        window.saysTagClick = function(tag) { self.filterByTag(tag); };
        // 开始加载数据
        this.loadMemos();
    }

    /**
     * 按标签过滤当前列表(点击标签时)
     * @param {string} tag 标签名,空字符串表示清除过滤
     */
    filterByTag(tag) {
        // 相同标签再点一次则当作清除过滤
        if (this.state.tag === tag) tag = '';
        // 更新状态
        this.state.tag = tag;
        this.state.currentPage = 1;
        this.state.hasMore = true;
        // 移除旧的加载按钮并重新创建(可能因上次数据加载完被移除)
        document.querySelectorAll('.bb-container').forEach(el => el.remove());
        this.createLoadButton();
        // 清空列表并显示加载动画
        this.container.innerHTML = '';
        this.showLoader();
        // 重新加载
        this.loadMemos();
    }

    /**
     * 显示加载器
     */
    showLoader() {
        this.container.innerHTML = `
            <div class="loader">
                <svg class="circular" viewBox="25 25 50 50">
                    <circle class="path" cx="50" cy="50" r="20" fill="none" stroke-width="2" stroke-miterlimit="10"/>
                </svg>
            </div>
        `;
    }

    /**
     * 初始化Markdown扩展
     */
    initMarkedExtensions() {
        // 自定义渲染器
        const renderer = new marked.Renderer();
        // 自定义图片解析
        renderer.image = (href) => {
            // console.log(href);
            // 渲染图片为带有特定类名和属性的div结构，以适应特定的样式和功能需求
            return `<img class="thumb" src="${href.href}" title="${href.text}' alt='${href.text}"/>`;
        };
        // 自定义链接渲染
        renderer.link = (href) => {
            // 如果链接文本就是链接本身，说明是直接贴的链接，可以转换为播放器
            const isDirectLink = href.text === href.href || href.text === href.href.replace(/^https?:\/\//, '');
            // 只有直链才进行转换，如果非直链，则不转换
            // 直接链接：https://music.163.com/song?id=12345 → 会被自动转换为播放器
            // Markdown链接：[歌曲名称](https://music.163.com/song?id=12345) 这样的，就不会被转换
            if (isDirectLink) {
                // 处理网易云音乐链接
                if (href.href.includes("music.163.com") && href.href.includes("id=")) {
                    const musicId = href.href.match(/id=([0-9]+)/);
                    if (musicId && musicId[1]) {
                        return `
                            <div class="w-full" title="${href.text}">
                                <iframe src="https://music.163.com/outchain/player?type=2&id=${musicId[1]}&auto=1&height=32" frameborder="no" border="0" width="100%" height="52" referrerpolicy="no-referrer"></iframe>
                            </div>
                        `;
                    }
                }
                // B站视频
                if (href.href.includes("bilibili.com/video/")) {
                    const bvid = href.href.match(/\/video\/((?:av[\d]{1,10})|(?:BV[\w]{10}))/);
                    if (bvid && bvid[1]) {
                        // 播放器类型，因人而异，因为有人喜欢带B站框的，有人喜欢简洁的，所以分开，但是mobile的这个，只要有autoplay，无论何值，他都会自动播放，所以将他区分开了
                        // 还可以选择这个播放器 //player.bilibili.com/player.html?isOutside=true&autoplay=0&bvid=${bvid[1]}";
                        return `
                            <div class="bilivideo">
                                <iframe src="//www.bilibili.com/blackboard/html5mobileplayer.html?danmaku=0&poster=true&bvid=${bvid[1]}" scrolling="no" border="0" frameborder="no" framespacing="0" allowfullscreen="true"></iframe>
                            </div>
                        `;
                    }
                }
            }
            // 解析豆瓣 - 分两种情况处理
            if (href.href.includes("douban.com")) {
                // console.log(href);
                // 情况1: 电影、书籍、音乐
                let idMatch = href.href.match(/https?:\/\/(movie|book|music)\.douban\.com\/subject\/(\d+)/);
                if (idMatch && idMatch[1] && idMatch[2]) {
                    const type = idMatch[1], id = idMatch[2], parts = href.text.split("|");
                    if (parts.length < 3) return `<a class="primary" href="${href.href}" target="_blank">${href.text}</a>`;
                    return this.generateDoubanCard({
                        id: id, type: type, title: parts[0] || "未知标题",  
                        author: parts.length > 4 ? parts[4] : null, 
                        rating: parts[1] || "?",  
                        description: parts[2] || "", 
                        cover: parts.length > 3 ? parts[3] : null, 
                        url: href.href
                    });
                }
                
                // 情况2: 游戏
                idMatch = href.href.match(/https?:\/\/(www\.)?douban\.com\/game\/(\d+)/);
                if (idMatch && idMatch[2]) {
                    const type = "game", id = idMatch[2], parts = href.text.split("|");
                    if (parts.length < 3) return `<a class="primary" href="${href.href}" target="_blank">${href.text}</a>`;
                    return this.generateDoubanCard({
                        id: id, type: type, title: parts[0] || "未知标题",  
                        author: parts.length > 4 ? parts[4] : null, 
                        rating: parts[1] || "?",  
                        description: parts[2] || "", 
                        cover: parts.length > 3 ? parts[3] : null, 
                        url: href.href
                    });
                }
            }
            return `<a class="primary" href="${href.href}" target="_blank">${href.text}</a>`;
        };
        // 渲染Markdown
        marked.setOptions({renderer: renderer, breaks: false, smartypants: false, langPrefix: "language-", headerIds: false, mangle: false});
        // 标签扩展
        const tagExtension = {
            name: "hashtag",
            level: "inline",
            start: src => src.match(/#[^#\s!.,;:?"'()]+/)?.index,
            tokenizer: src => {
                const rule = /#([^#\s!.,;:?"'()]+)(?= )/, match = rule.exec(src);
                if (match) return { type: "hashtag", raw: match[0], tag: match[1], tokens: [] };
            },
            renderer: token => `<a class='tag-span' href="javascript:;" data-tag="${token.tag}" onclick="window.saysTagClick && window.saysTagClick('${token.tag}')">#${token.tag}</a>`,
        };
        marked.use({ extensions: [tagExtension] });
    }

    /**
     * 生成豆瓣卡片视图
     * @param {Object} info - 豆瓣信息对象
     */
    generateDoubanCard(info) {
        const coverImg = info.cover || `https://img1.doubanio.com/view/subject/s/public/${info.id}.jpg`;
        return `<div class="douban-card">
            <div class="cover">
                <img class="cover-img loaded1" referrerpolicy="no-referrer" src="${coverImg}" alt="${info.title}">
            </div>
            <div class="info">
                <div class="douban-card-title"><a href="${info.url}" class="cute" target="_blank" rel="noreferrer" title="点击前往豆瓣查看「${info.title}」详情">${info.title}</a>
                    ${info.author ? `- ${info.author}` : ""}
                </div>
                <div class="douban-card-rating">
                    <span class="rating-star allstar${Math.floor(info.rating)} leading-4"></span>
                    <span class="rating-text">${info.rating}</span>
                </div>
                <div class="douban-card-desc">${info.description}</div>
            </div>
            <div class="douban-card-type">${info.type}</div>
        </div>`;
    }

    /**
     * 创建加载更多按钮
     */
    createLoadButton() {
        this.container.insertAdjacentHTML("afterend", '<div class="says-card bb-container"><button class="bb-load">加载中…</button></div>');
        this.loadButton = document.querySelector(".bb-load");
        this.loadButton.addEventListener("click", () => {
            if (!this.state.isLoading && this.state.hasMore) {
                this.loadButton.textContent = "^_^努力加载中… ";
                this.loadButton.classList.add("loading");
                this.loadMemos();
            }
        });
    }

    /**
     * 异步加载备忘录数据
     */
    async loadMemos() {
        // 加锁
        if (this.state.isLoading || !this.state.hasMore) return;
        this.state.isLoading = true;
        try {
            // 当前激活的标签(空 = 全部)
            const activeTag = this.state.tag || '';
            // 拼接 URL(带标签过滤)
            const url = `${this.config.memos}api/v1/memo?limit=${this.config.limit}&page=${this.state.currentPage}` + (activeTag ? `&tag=${encodeURIComponent(activeTag)}` : '');
            const response = await fetch(url);
            const ret = await response.json();
            const data = ret.data || [];
            if (data.length > 0) {
                await this.renderMemos(data);
                this.state.currentPage++;
            }
            if (data.length < this.config.limit || data.length === 0) {
                this.state.hasMore = false;
                document.querySelector(".bb-container")?.remove();
            } else {
                this.loadButton.textContent = "点击加载更多";
                this.loadButton.classList.remove("loading");
                this.loadButton.style.display = "block";
            }
        } catch (error) {
            console.error("加载失败:", error);
            this.loadButton.textContent = "加载失败，点击重试";
            this.loadButton.classList.remove("loading");
        } finally {
            this.state.isLoading = false;
        }
    }

    /**
     * 异步渲染说说列表
     * @param {Array} data - 包含说说信息的数组
     */
    async renderMemos(data) {
        let html = "";
        for (let i = 0; i < data.length; i++) {
            const memo = data[i];
            const content = marked.parse(memo.content);
            const from = memo.from || "";
            const position = memo.position || "";
            const timestamp = this.formatTime(memo.updated_at);
            html += `<div class="says-card">
                <div class="says-content">${content}</div>
                <div class="says-footer">
                    <div class="meta datatime" data-value="${memo.updated_at}">${timestamp}</div>
            `;
            // 来源 / 位置,空值不渲染
            if (from) html += `<div class="meta from">---来自「${from}」</div>`;
            if (position) html += `<div class="meta position">📍 ${position}</div>`;
            // 继续
            html += `</div></div>`;
        }
        // 添加图片
        const loader = this.container.querySelector(".loader");
        if (loader) loader.remove();
        this.container.insertAdjacentHTML("beforeend", `<!--说说主体开始-->${html}<!--说说主体结束-->`);
        // 使用全局懒加载管理器处理新加载的内容
        if (window.lazyLoadManager) window.lazyLoadManager.processImages(".says-content");
    }

    /**
     * 格式化时间
     * @param {number} timestamp - 时间戳
     */
    formatTime(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const date = new Date(timestamp * 1000);
        const time = [date.getHours().toString().padStart(2, "0"), date.getMinutes().toString().padStart(2, "0")].join(":");
        const timeDiff = now - timestamp;
        if (timeDiff < 60 * 60) {
            return Math.floor(timeDiff / 60) + "分钟前";
        } else if (timeDiff < 24 * 60 * 60) {
            return Math.floor(timeDiff / (60 * 60)) + "小时前";
        } else if (timeDiff < 2 * 24 * 60 * 60) {
            return "昨天 " + time;
        } else if (timeDiff < 3 * 24 * 60 * 60) {
            return "前天 " + time;
        } else {
            return ([date.getFullYear(), (date.getMonth() + 1).toString().padStart(2, "0"), date.getDate().toString().padStart(2, "0")].join("/") + " <span class='time'>" + time + '</span>');
        }
    }
}