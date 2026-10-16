<?php
// 启用严格类型
declare(strict_types=1);

namespace TypechoPlugin\Says;

// 防止直接访问
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Db;

/**
 * 附件管理类
 *
 * 负责附件表(says_attachment)和说说-附件关联表(says_attachment_meta)的读写。
 * 核心逻辑:
 *   - 上传附件:调存储上传,按 (storage, md5) 去重,已存在则 num+1 复用
 *   - 删除附件:删关联记录,num-1,归零才真正删存储中的文件
 *   - 获取附件:二次查询(meta → attachment),避免 join 字段冲突
 *
 * 真正上传/删除文件的行为委托给 getStorage() 返回的存储实例,本类只负责数据库层。
 */
class Attachment
{
    /**
     * 上传附件到存储并登记数据库,并关联到指定说说
     *
     * @param int $sayId 关联的说说 id
     * @param string $source 本地文件路径,或 base64 数据($isbase64 为 true 时)
     * @param string $target 目标路径/文件名
     * @param bool $isbase64 是否为 base64 编码的数据
     * @return int 附件记录 id
     * @throws \Exception 上传或入库失败时抛出异常
     */
    public static function add(int $sayId, string $source, string $target, $isbase64 = false): int {
        // 上传附件并登记,返回附件行(含 id)
        $attachment = self::upload($source, $target, $isbase64);
        // 关联到说说
        self::relate($sayId, [$attachment['id']]);
        // 返回附件记录 id
        return $attachment['id'];
    }

    /**
     * 上传附件到存储并登记数据库(暂存,num=0)
     *
     * 上传的附件先放在暂存区(num=0,未关联任何说说),
     * 发表/编辑时再通过 relate() 关联到说说。
     * 按 (storage, md5) 去重,已存在则直接复用,不重复上传。
     *
     * @param string $source 本地文件路径,或 base64 数据($isbase64 为 true 时)
     * @param string $target 目标路径/文件名
     * @param bool $isbase64 是否为 base64 编码的数据
     * @return array 附件行数据(含 id)
     * @throws \Exception 上传或入库失败时抛出异常
     */
    public static function upload(string $source, string $target, $isbase64 = false): array {
        // 数据库实例
        $db = Db::get();
        // 调用存储上传,返回附件行数据
        $uploaded = getStorage()->upload($source, $target, $isbase64);
        // 按 (storage + md5) 查重,已存在则复用
        $exists = $db->fetchRow($db->select()
            ->from('table.says_attachment')
            ->where('storage = ? AND md5 = ?', $uploaded['storage'], $uploaded['md5'])
            ->limit(1));
        // 查重
        if ($exists) {
            // 已存在:直接复用(不重复上传,不增加计数)
            return $exists;
        }
        // 不存在:插入新附件行,num=0 表示未关联
        $uploaded['id'] = (int)$db->query($db->insert('table.says_attachment')->rows([
            'name'       => $uploaded['name'],
            'storage'    => $uploaded['storage'],
            'num'        => 0,
            'url'        => $uploaded['url'],
            'size'       => $uploaded['size'],
            'mime'       => $uploaded['mime'],
            'md5'        => $uploaded['md5'],
            'remark'     => $uploaded['remark'],
            'created_at' => time(),
        ]));
        // 返回附件行数据(含 id)
        return $uploaded;
    }

    /**
     * 批量关联附件到说说,引用计数 +1
     *
     * 仅对「尚未关联到该说说」的附件计数,避免重复关联导致计数错乱。
     *
     * @param int $sayId 说说 id
     * @param array $attachmentIds 附件 id 列表
     * @return void
     */
    public static function relate(int $sayId, array $attachmentIds) {
        // 数据库实例
        $db = Db::get();
        // 逐个关联
        foreach ($attachmentIds as $attachmentId) {
            // 转整型
            $attachmentId = (int)$attachmentId;
            // 无效则跳过
            if ($attachmentId <= 0) continue;
            // 查附件是否存在
            $attachment = $db->fetchRow($db->select()
                ->from('table.says_attachment')
                ->where('id = ?', $attachmentId)
                ->limit(1));
            // 不存在则跳过
            if (!$attachment) continue;
            // 检查是否已关联该说说
            $has = $db->fetchRow($db->select()
                ->from('table.says_attachment_meta')
                ->where('say_id = ? AND attachment_id = ?', $sayId, $attachmentId)
                ->limit(1));
            // 未关联才处理
            if (!$has) {
                // 写入关联
                $db->query($db->insert('table.says_attachment_meta')->rows([
                    'say_id'        => $sayId,
                    'attachment_id' => $attachmentId,
                    'created_at'    => time(),
                ]));
                // 引用计数 +1
                $db->query($db->update('table.says_attachment')
                    ->rows(['num' => (int)$attachment['num'] + 1])
                    ->where('id = ?', $attachmentId));
            }
        }
    }

    /**
     * 删除某条说说的所有附件
     *
     * @param int $sayId 说说 id
     * @return void
     */
    public static function deleteBySay(int $sayId) {
        // 数据库实例
        $db = Db::get();
        // 1. 查关联表,拿该说说所有 attachment_id
        $metas = $db->fetchAll($db->select()->from('table.says_attachment_meta')->where('say_id = ?', $sayId));
        // 没有关联,直接返回
        if (empty($metas)) return;
        // 2. 逐条处理
        foreach ($metas as $meta) {
            // 附件 id
            $attachmentId = (int)$meta['attachment_id'];
            // 查附件详情
            $attachment = $db->fetchRow($db->select()->from('table.says_attachment')->where('id = ?', $attachmentId)->limit(1));
            // 附件不存在则只删关联
            if (!$attachment) {
                $db->query($db->delete('table.says_attachment_meta')->where('say_id = ? AND attachment_id = ?', $sayId, $attachmentId));
                continue;
            }
            // 先删关联记录
            $db->query($db->delete('table.says_attachment_meta')->where('say_id = ? AND attachment_id = ?', $sayId, $attachmentId));
            // 引用计数减一
            $newNum = (int)$attachment['num'] - 1;
            // 更新计数
            $db->query($db->update('table.says_attachment')->rows(['num' => $newNum])->where('id = ?', $attachmentId));

            // 计数归零,真正删除存储文件并删除附件行
            if ($newNum <= 0) {
                // 调用存储删除文件
                try {
                    getStorage()->delete($attachment);
                } catch (\Exception $e) {
                    // 删除失败不阻断主流程
                }
                // 删除附件行
                $db->query($db->delete('table.says_attachment')->where('id = ?', $attachmentId));
            }
        }
    }

    /**
     * 获取某条说说的所有附件
     *
     * @param int $sayId 说说 id
     * @return array 附件列表
     */
    public static function getBySay(int $sayId): array {
        // 数据库实例
        $db = Db::get();

        // 1. 查关联表,拿该说说的 attachment_id 列表
        $metas = $db->fetchAll($db->select()->from('table.says_attachment_meta')->where('say_id = ?', $sayId)->order('id', Db::SORT_ASC));
        // 没有关联,返回空数组
        if (empty($metas)) return [];
        // 2. 收集 attachment_id
        $ids = array_map(function ($meta) { return (int)$meta['attachment_id'];}, $metas);
        // 3. 查附件详情
        $attachments = $db->fetchAll($db->select()->from('table.says_attachment')->where('id IN ?', $ids));
        // 4. 按关联顺序返回
        $map = [];
        foreach ($attachments as $a) {
            $map[(int)$a['id']] = $a;
        }
        $result = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $result[] = $map[$id];
            }
        }
        // 返回附件列表
        return $result;
    }

    /**
     * 按附件 id 获取附件详情
     *
     * @param int $attachmentId 附件 id
     * @return array|null 附件行,不存在返回 null
     */
    public static function getById(int $attachmentId): ?array {
        // 数据库实例
        $db = Db::get();
        // 查询附件
        $row = $db->fetchRow($db->select()->from('table.says_attachment')->where('id = ?', $attachmentId)->limit(1));
        // 返回结果
        return $row ?: null;
    }

    /**
     * 获取未使用的附件(暂存区)
     *
     * 即 num=0、未被任何说说关联的附件,显示在发表框下方供选择使用
     *
     * @param int $limit 数量限制,默认 50
     * @return array 附件列表(按创建时间倒序)
     */
    public static function getUnused(int $limit = 50): array {
        // 数据库实例
        $db = Db::get();
        // 查询未使用附件
        return $db->fetchAll($db->select()
            ->from('table.says_attachment')
            ->where('num = ?', 0)
            ->order('created_at', Db::SORT_DESC)
            ->limit($limit));
    }

    /**
     * 删除单个附件
     *
     * - 未被使用(num=0):删除物理文件 + 附件行,用于暂存区删除
     * - 已被使用(num>0):解除与指定说说的关联并计数 -1,用于编辑时删除
     *
     * @param int $attachmentId 附件 id
     * @param int|null $sayId 关联的说说 id(已使用时必传,用于解除与该说说的关联)
     * @return bool 是否处理成功
     */
    public static function remove(int $attachmentId, ?int $sayId = null): bool {
        // 无效 id 直接失败
        if ($attachmentId <= 0) return false;
        // 数据库实例
        $db = Db::get();
        // 查附件详情
        $attachment = $db->fetchRow($db->select()
            ->from('table.says_attachment')
            ->where('id = ?', $attachmentId)
            ->limit(1));
        // 不存在则失败
        if (!$attachment) return false;

        // 已被使用:解除与指定说说的关联,计数 -1,不删物理文件
        if ((int)$attachment['num'] > 0) {
            // 需要指定 sayId
            if (empty($sayId)) return false;
            // 检查关联是否存在
            $has = $db->fetchRow($db->select()
                ->from('table.says_attachment_meta')
                ->where('say_id = ? AND attachment_id = ?', $sayId, $attachmentId)
                ->limit(1));
            // 无关联则失败
            if (!$has) return false;
            // 删除关联记录
            $db->query($db->delete('table.says_attachment_meta')
                ->where('say_id = ? AND attachment_id = ?', $sayId, $attachmentId));
            // 计数减一
            $newNum = (int)$attachment['num'] - 1;
            // 更新计数
            $db->query($db->update('table.says_attachment')
                ->rows(['num' => $newNum])
                ->where('id = ?', $attachmentId));
            // 计数归零则删除物理文件 + 附件行
            if ($newNum <= 0) {
                // 删除物理文件
                try {
                    getStorage()->delete($attachment);
                } catch (\Exception $e) {
                    // 物理删除失败不阻断
                }
                // 删除附件行
                $db->query($db->delete('table.says_attachment')->where('id = ?', $attachmentId));
            }
            // 返回成功
            return true;
        }

        // 未被使用:删除物理文件 + 附件行
        try {
            getStorage()->delete($attachment);
        } catch (\Exception $e) {
            // 物理删除失败不阻断,继续删记录
        }
        // 删除附件行
        $db->query($db->delete('table.says_attachment')->where('id = ?', $attachmentId));
        // 返回成功
        return true;
    }
}