<?php
declare(strict_types=1);

/**
 * 卿辰导航 — 示例数据
 *
 * 仅在首次安装时导入一次（settings.seeded = 1 后不再执行）。
 * 之后所有增删改都走后台，不会回滚到这里。
 *
 * 这些只是示例，装上后请到后台把「我的站点」改成你自己的。
 *
 * @since  2026-10-19
 */

// 禁止被直接访问（本文件只应通过 require 引入）
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

function nav_seed(): void
{
    $pdo  = db();
    $now  = nav_now();

    /**
     * 分类：name => [icon, accent, sites[]]
     * sites 每项：[name, url, descr, badge, highlight, tags]
     */
    $tree = [

        /* ================= 我的站点（示例，请替换） ================= */
        '我的站点' => ['🏠', '#C9A227', [
            ['我的博客', 'https://example.com', '把这一条改成你自己的网站',      '', 1, '示例'],
            ['我的项目', 'https://example.org', '不需要就删掉，或换成项目地址',  '', 0, '示例'],
        ]],

        /* ================= AI 对话 ================= */
        'AI 对话' => ['💬', '#4FA89B', [
            ['ChatGPT',  'https://chatgpt.com',        'OpenAI 官方对话',        '', 1, '通用'],
            ['Claude',   'https://claude.ai',          'Anthropic · 长文与代码', '', 1, '通用,写作'],
            ['Gemini',   'https://gemini.google.com',  'Google 多模态',          '', 0, '通用'],
            ['DeepSeek', 'https://chat.deepseek.com',  '深度求索 · 推理强',      '', 1, '国产,推理'],
            ['Kimi',     'https://kimi.moonshot.cn',   '月之暗面 · 长上下文',    '', 0, '国产'],
            ['豆包',     'https://www.doubao.com',     '字节跳动',               '', 0, '国产'],
        ]],

        /* ================= 开发工具 ================= */
        '开发工具' => ['🛠', '#5B8DEF', [
            ['GitHub',         'https://github.com',            '代码托管',       '', 1, '代码'],
            ['MDN',            'https://developer.mozilla.org', 'Web 开发文档',   '', 1, '文档'],
            ['Stack Overflow', 'https://stackoverflow.com',     '技术问答',       '', 0, '问答'],
            ['npm',            'https://www.npmjs.com',         'JS 包管理',      '', 0, '包管理'],
            ['Can I use',      'https://caniuse.com',           '浏览器兼容查询', '', 0, '兼容'],
            ['CodePen',        'https://codepen.io',            '在线前端试验场', '', 0, '在线'],
        ]],

        /* ================= 设计素材 ================= */
        '设计素材' => ['🎨', '#D97BA0', [
            ['Figma',    'https://www.figma.com',   '协作设计',     '', 1, 'UI'],
            ['Dribbble', 'https://dribbble.com',    '设计灵感',     '', 0, '灵感'],
            ['iconfont', 'https://www.iconfont.cn', '阿里图标库',   '', 0, '图标'],
            ['Unsplash', 'https://unsplash.com',    '免费高清图',   '', 0, '图片'],
            ['Pexels',   'https://www.pexels.com',  '免费图片视频', '', 0, '图片'],
        ]],

        /* ================= 效率工具 ================= */
        '效率工具' => ['⚡', '#C9A227', [
            ['Notion',     'https://www.notion.so',   '笔记与数据库',   '', 1, '笔记'],
            ['语雀',       'https://www.yuque.com',   '中文知识库',     '', 0, '笔记'],
            ['Excalidraw', 'https://excalidraw.com',  '手绘风白板',     '', 1, '画图'],
            ['Trello',     'https://trello.com',      '看板式任务管理', '', 0, '任务'],
            ['Toggl',      'https://toggl.com',       '时间记录',       '', 0, '时间'],
        ]],

        /* ================= 云与部署 ================= */
        '云与部署' => ['☁️', '#6FA898', [
            ['Vercel',     'https://vercel.com',          '前端一键部署', '', 1, '托管'],
            ['Cloudflare', 'https://dash.cloudflare.com', 'CDN 与 DNS',   '', 1, 'CDN'],
            ['Railway',    'https://railway.app',         '后端托管',     '', 0, '托管'],
            ['阿里云',     'https://www.aliyun.com',      '云服务器',     '', 0, '国内'],
            ['腾讯云',     'https://cloud.tencent.com',   '云服务器',     '', 0, '国内'],
        ]],
    ];

    $insCat  = $pdo->prepare(
        'INSERT INTO categories (name, icon, accent, sort_order, visible, created_at)
         VALUES (?, ?, ?, ?, 1, ?)'
    );
    $insSite = $pdo->prepare(
        'INSERT INTO sites
         (category_id, name, url, descr, icon, initial, tags, badge, highlight,
          sort_order, visible, hit_count, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)'
    );

    $catOrder = 0;
    foreach ($tree as $catName => $def) {
        [$icon, $accent, $sites] = $def;
        $catOrder += 10;
        $insCat->execute([$catName, $icon, $accent, $catOrder, $now]);
        $catId = (int) $pdo->lastInsertId();

        $siteOrder = 0;
        foreach ($sites as $s) {
            [$name, $url, $descr, $badge, $hl, $tags] = $s;
            $siteOrder += 10;
            $insSite->execute([
                $catId,
                $name,
                $url,
                $descr,
                '',                                        // icon 先留空，前端自动取 favicon
                mb_substr($name, 0, 1, 'UTF-8'),
                $tags,
                $badge,
                $hl,
                $siteOrder,
                $now,
                $now,
            ]);
        }
    }
}
