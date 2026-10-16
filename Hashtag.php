<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Db;

/**
 * 标签(Hashtag)管理类
 *
 * 负责标签表(says_hashtag)和说说-标签关联表(says_hashtag_meta)的读写。
 * 核心逻辑:
 *   - 提取:从说说内容中解析 #标签(markdown 渲染规则同 says.js 的 hashtag 扩展)
 *   - 落库:发布/编辑时写入标签及关联,count 统计
 *   - 清理:编辑重建关联、删除说说反扣 count,count 归零的孤儿标签可清理
 */
class Hashtag
{
    /**
     * 从内容中提取所有标签
     *
     * 与前端 says.js 的 hashtag 扩展保持同规则:
     *   #[^#\s!.,;:?"'()]+ (后面需跟空白或占位)
     * 先剥离代码块/行内代码,避免代码里的 # 被误判为标签。
     *
     * @param string $content 说说内容(markdown)
     * @return array 去重后的标签名列表
     */
    public static function extract(string $content): array
    {
        // 空内容直接返回
        if (trim($content) === '') return [];
        // 第一步:去掉行内代码 `...` 与代码块 ```...
        $text = preg_replace('/```[\s\S]*?```/', '', $content);
        $text = preg_replace('/`[^`]*`/', '', $text);
        // 第二步:同时去掉 markdown 链接的标题部分,避免 [文本|...](url) 里的 # 干扰(与前端规则一致,仅取纯文本)
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        // 提取标签:与前端一致,标签后必须紧跟空白或到行尾
        if (preg_match_all('/#([^#\s!.,;:?"\'()]+)(?=\s|$)/u', $text, $matches)) {
            // 去重、去空后返回
            return array_values(array_unique(array_filter($matches[1])));
        }
        // 没匹配到返回空
        return [];
    }

    /**
     * 获取标签(按 name)
     *
     * @param string $name 标签名
     * @return array|null 标签行,不存在返回 null
     */
    public static function getByName(string $name): ?array
    {
        // 数据库实例
        $db = Db::get();
        // 查询
        $row = $db->fetchRow($db->select()
            ->from('table.says_hashtag')
            ->where('name = ?', $name)
            ->limit(1));
        // 返回
        return $row ?: null;
    }

    /**
     * 批量关联标签到说说(全量重建)
     *
     * 直接删除该说说的旧关联并反扣 count,再写入新关联。
     * 新增标签 count+1;仍然保留的标签,删除时 -1、新增时 +1,抵消。
     *
     * @param int $sayId 说说 id
     * @param array $tags 标签名数组
     * @return void
     */
    public static function relate(int $sayId, array $tags)
    {
        // 数据库实例
        $db = Db::get();
        // 旧关联计数先清掉(全量重建:先删后插)
        self::deleteBySay($sayId, true);
        // 没标签直接返回
        if (empty($tags)) return;
        // 逐个写关联
        foreach ($tags as $tag) {
            // 规范化标签名
            $tag = trim((string)$tag);
            // 空标签跳过
            if ($tag === '') continue;
            // 查标签是否存在
            $exists = $db->fetchRow($db->select()
                ->from('table.says_hashtag')
                ->where('name = ?', $tag)
                ->limit(1));
            // 不存在则创建
            if (!$exists) {
                // 写入新标签,count 从 1 开始
                $tagId = (int)$db->query($db->insert('table.says_hashtag')->rows([
                    'name'       => $tag,
                    'count'      => 1,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]));
            } else {
                // 存在则 count +1
                $tagId = (int)$exists['id'];
                // 更新计数
                $db->query($db->update('table.says_hashtag')
                    ->rows(['count' => (int)$exists['count'] + 1, 'updated_at' => time()])
                    ->where('id = ?', $tagId));
            }
            // 写入关联
            $db->query($db->insert('table.says_hashtag_meta')->rows([
                'say_id'     => $sayId,
                'hashtag_id' => $tagId,
                'created_at' => time(),
            ]));
        }
    }

    /**
     * 删除说说的所有标签关联,并反扣 count
     *
     * @param int $sayId 说说 id
     * @param bool $silent 内部重建调用时静默(不删孤儿标签),默认 false 顺手清理孤儿
     * @return void
     */
    public static function deleteBySay(int $sayId, bool $silent = false)
    {
        // 数据库实例
        $db = Db::get();
        // 查关联
        $metas = $db->fetchAll($db->select()
            ->from('table.says_hashtag_meta')
            ->where('say_id = ?', $sayId));
        // 无关联直接返回
        if (empty($metas)) return;
        // 逐个处理
        foreach ($metas as $meta) {
            // 标签 id
            $tagId = (int)$meta['hashtag_id'];
            // 查标签
            $tag = $db->fetchRow($db->select()->from('table.says_hashtag')->where('id = ?', $tagId)->limit(1));
            // 删关联
            $db->query($db->delete('table.says_hashtag_meta')->where('say_id = ? AND hashtag_id = ?', $sayId, $tagId));
            // 标签不存在则跳过
            if (!$tag) continue;
            // 反扣 count
            $newCount = (int)$tag['count'] - 1;
            // 更新
            $db->query($db->update('table.says_hashtag')
                ->rows(['count' => max(0, $newCount), 'updated_at' => time()])
                ->where('id = ?', $tagId));
            // 非静默:count 归零且无任何关联时删除孤儿标签
            if (!$silent && $newCount <= 0) {
                // 再确认没有其他说说关联
                $left = $db->fetchRow($db->select()
                    ->from('table.says_hashtag_meta')
                    ->where('hashtag_id = ?', $tagId)
                    ->limit(1));
                // 无关联才删除标签行
                if (!$left) {
                    $db->query($db->delete('table.says_hashtag')->where('id = ?', $tagId));
                }
            }
        }
    }

    /**
     * 获取某条说说的所有标签(供列表/编辑展示)
     *
     * @param int $sayId 说说 id
     * @return array 标签行列表
     */
    public static function getBySay(int $sayId): array
    {
        // 数据库实例
        $db = Db::get();
        // 查关联
        $metas = $db->fetchAll($db->select()
            ->from('table.says_hashtag_meta')
            ->where('say_id = ?', $sayId)
            ->order('id', Db::SORT_ASC));
        // 无关联返回空
        if (empty($metas)) return [];
        // 收集标签 id
        $ids = array_map(function ($meta) { return (int)$meta['hashtag_id']; }, $metas);
        // 查标签详情
        $tags = $db->fetchAll($db->select()->from('table.says_hashtag')->where('id IN ?', $ids));
        // 按关联顺序返回
        $map = [];
        foreach ($tags as $t) $map[(int)$t['id']] = $t;
        $result = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) $result[] = $map[$id];
        }
        // 返回
        return $result;
    }

    /**
     * 获取热门标签(按 count 降序)
     *
     * @param int $limit 数量限制,默认 20
     * @return array 标签行列表
     */
    public static function getHot(int $limit = 20): array
    {
        // 数据库实例
        $db = Db::get();
        // 查询并按使用次数降序
        return $db->fetchAll($db->select()
            ->from('table.says_hashtag')
            ->where('count > ?', 0)
            ->order('count', Db::SORT_DESC)
            ->order('updated_at', Db::SORT_DESC)
            ->limit($limit));
    }
}