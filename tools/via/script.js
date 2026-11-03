// ==UserScript==
// @name         豆瓣数据获取
// @namespace    http://tampermonkey.net/
// @version      1.0.9
// @description  导出卡片格式 [名称|评分|简介|海报](PC豆瓣链接)，海报代理dban.bsay.de，清理br标签，简介上限275字符（含标点），超长截断追加...
// @author       custom
// @match        https://movie.douban.com/subject/*/
// @match        https://m.douban.com/*/subject/*
// @run-at       document-end
// ==/UserScript==
(function() {
    'use strict';

    const floatBtn = document.createElement('button');
    floatBtn.innerText = "📋复制卡片";
    floatBtn.style.position = "fixed";
    floatBtn.style.right = "12px";
    floatBtn.style.bottom = "40px";
    floatBtn.style.zIndex = "999999";
    floatBtn.style.padding = "6px 10px";
    floatBtn.style.fontSize = "13px";
    floatBtn.style.borderRadius = "8px";
    document.body.appendChild(floatBtn);

    // 清理：<br> <br/> <br /> 全部删掉，再清理换行、多余空白
    function cleanText(str) {
        if (!str) return "";
        str = str.replace(/<br.*?\/?>/gi, '');
        str = str.replace(/\r?\n/g, '').replace(/\s+/g, ' ').trim();
        return str;
    }

    // 简介截断，最长275字（含标点），超出末尾加...
    function trimIntro(str, maxLen = 220) {
        if (str.length <= maxLen) return str;
        return str.slice(0, maxLen) + "...";
    }

    // 海报地址转换：去掉 https:// 加上代理前缀
    function proxyPoster(url) {
        if (!url) return "";
        const noProto = url.replace(/^https?:\/\//, '');
        return `https://dban.bsay.de/${noProto}`;
    }

    // m链接转对应PC域名
    // m.douban.com/book/subject/123 → book.douban.com/subject/123
    function m2PcUrl(mUrl) {
        const u = new URL(mUrl);
        const pathParts = u.pathname.split('/').filter(Boolean);
        const type = pathParts[0];
        const id = pathParts[2];
        const domainMap = {
            movie: 'movie.douban.com',
            book: 'book.douban.com',
            game: 'game.douban.com',
            music: 'music.douban.com',
            drama: 'movie.douban.com'
        };
        const pcDomain = domainMap[type] || 'movie.douban.com';
        return `https://${pcDomain}/subject/${id}/`;
    }

    floatBtn.addEventListener('click', async ()=>{
        let title = "";
        let rating = "";
        let intro = "";
        let posterRaw = "";
        let pcLink = "";

        if (location.hostname === "m.douban.com") {
            // 移动端 m.douban
            title = document.querySelector('.sub-title')?.textContent ?? "";
            const metaRating = document.querySelector('meta[itemprop="ratingValue"]');
            rating = metaRating?.content ?? "";
            intro = document.querySelector('.subject-intro .bd p[data-clamp]')?.textContent ?? "";
            posterRaw = document.querySelector('.sub-cover img')?.src ?? "";
            pcLink = m2PcUrl(location.href);
        } else {
            // PC movie.douban.com
            title = document.querySelector('h1 span')?.textContent ?? "";
            rating = document.querySelector('strong[property="v:average"]')?.textContent ?? "";
            intro = (document.querySelector('span[property="v:summary"]') || document.querySelector('#summary'))?.textContent ?? "";
            posterRaw = document.querySelector('#mainpic img')?.src ?? "";
            pcLink = location.href;
        }

        title = cleanText(title);
        rating = cleanText(rating);
        intro = cleanText(intro);
        intro = trimIntro(intro, 220);
        const poster = proxyPoster(posterRaw);

        const card = `[${title}|${rating}|${intro}|${poster}](${pcLink})`;

        try {
            await navigator.clipboard.writeText(card);
            alert("✅复制成功：\n" + card);
        } catch (err) {
            alert("❌复制失败，手动复制：\n" + card);
        }
    })
})();
