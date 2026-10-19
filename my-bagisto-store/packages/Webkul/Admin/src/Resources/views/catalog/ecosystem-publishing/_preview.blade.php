<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · 后台安全预览</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; background: #F5F7FB; color: #1F2937; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif; }
        .bar { background: #102F6E; color: #fff; padding: 14px 20px; font-size: 14px; }
        .bar b { font-size: 16px; }
        .wrap { max-width: 960px; margin: 20px auto; padding: 0 16px 40px; }
        .card { background: #fff; border: 1px solid #E5EAF3; border-radius: 10px; padding: 20px 22px; }
        .notice { margin-bottom: 14px; padding: 12px 14px; border-radius: 8px; background: #FFF8EC; border: 1px solid #F2D8A8; color: #7C4A03; font-size: 13px; }
        .danger { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
        table.meta { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.meta th { width: 130px; text-align: left; color: #6B7280; font-weight: 500; padding: 6px 10px 6px 0; vertical-align: top; white-space: nowrap; }
        table.meta td { padding: 6px 0; word-break: break-all; }
        .body { margin-top: 18px; padding-top: 18px; border-top: 1px solid #E5EAF3; font-size: 15px; line-height: 1.8; }
        .body img { max-width: 100%; height: auto; }
        .body table { max-width: 100%; border-collapse: collapse; }
        .body th, .body td { border: 1px solid #D1D5DB; padding: 6px 8px; }
        .media { margin-top: 18px; padding-top: 18px; border-top: 1px solid #E5EAF3; font-size: 12px; color: #4B5563; }
        .media code { background: #F3F4F6; padding: 1px 4px; border-radius: 4px; }
        .hint { margin-top: 18px; font-size: 12px; color: #6B7280; }
    </style>
</head>
<body>
    <div class="bar">
        <b>{{ $title }}</b>
        <span style="opacity:.75"> · 后台安全预览 · 不生成公开预览 token · revision #{{ $revisionId }}</span>
    </div>

    <div class="wrap">
        <div class="card">
            @if ($stale)
                <div class="notice danger">
                    该 revision 的正文净化版本与当前配置不一致，已停止渲染正文（fail-closed）。
                    请创建新的 revision 后重新预览；不会把旧净化结果当作安全内容输出。
                </div>
            @endif

            <table class="meta">
                @foreach ($meta as $label => $value)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>{{ $value !== '' ? $value : '—' }}</td>
                    </tr>
                @endforeach
            </table>

            @if ($html !== null && trim($html) !== '')
                <div class="body">{!! $html !!}</div>
            @elseif (! $stale)
                <div class="body"><span style="color:#6B7280">（无正文）</span></div>
            @endif

            @if ($media->isNotEmpty())
                <div class="media">
                    <b>媒体引用（只显示元数据，不含 storage_key / 私有路径）</b>
                    <ul>
                        @foreach ($media as $item)
                            <li>
                                media_id=<code>{{ $item->media_id }}</code>
                                role=<code>{{ $item->role }}</code>
                                review=<code>{{ $item->review_status }}</code>
                                alt={{ $item->alt_text ?: '—' }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="hint">
                此页面仅对具备对应 view 权限的后台账号开放；响应头固定
                <code>Cache-Control: no-store</code> 与 <code>X-Robots-Tag: noindex</code>。
                不展示审核备注、证据引用、存储 key 或工作 revision 指针。
            </p>
        </div>
    </div>
</body>
</html>
