<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 小召·召唤引擎 v2(AI 版,由 Coze Agent 驱动)。
 *
 * ⚠ 这是**独立新增**的控制器,与 SummonController(rule-v1)并存:
 *   - POST /api/summon      → SummonController   (rule-v1,原样不动)
 *   - POST /api/summon-ai   → 本控制器            (AI 版,新路由)
 * 现有前端仍走 /api/summon,行为零变化。
 *
 * 数据流(方案 B:预取,不让 Coze 回调本服务):
 *   1. 本地 SQL 查出机型库(几十台量级),压成紧凑文本
 *   2. 把「机型库 + 用户需求」一起发给 Coze Agent
 *   3. Agent 纯推理,返回推荐 → 本控制器解析回结构化契约
 *
 * 为什么不让 Coze 回调本服务取数(插件/工具方案):
 *   本地 `php artisan serve` 是**单进程**的,本请求会占住唯一的工作进程,
 *   而 Coze 回调又要打回本服务 → 自死锁(实测:请求永不返回)。
 *   预取方案不依赖服务端并发,本地/生产都成立,且少一次网络往返。
 *
 * 返回契约:与 rule-v1 同构(success/q/scene/machines/alternatives/engine),
 * 另**追加**字段(intent/answer/match_reason),只加不改,前端旧代码不会崩。
 *
 * 降级策略:Coze 调不通或超时 → 自动回退 rule-v1,保证接口永远有响应。
 *
 * 配置(.env,不进版本库):
 *   COZE_BASE_URL / COZE_PAT / COZE_BOT_ID
 */
class SummonAiController extends APIController
{
    /** 传给 Agent 的每类条目上限(防止 prompt 过长) */
    private const CATALOG_LIMIT = 200;

    /**
     * 三个库各自的分类 slug。
     * 技能和配件是**独立于机型的库**,用户有需求时也能被筛选出来。
     */
    private const TYPE_CATEGORIES = [
        'machines'    => ['rent', 'buy'],   // 机型:分「租用」「购买」两个分类
        'skills'      => ['skill'],         // 技能库
        'accessories' => ['accessory'],     // 配件库
    ];

    private const TYPE_LABELS = [
        'machines'    => '机型库',
        'skills'      => '技能库',
        'accessories' => '配件库',
    ];

    public function summon(Request $request): JsonResponse
    {
        // ⚠ 必须放宽 PHP 执行时限:默认 max_execution_time=30s,
        // 而 Coze 是异步的,需要轮询等待,实测端到端约 10~30s。
        @set_time_limit(240);

        $q = trim((string) $request->input('q', ''));

        if ($q === '') {
            return response()->json(['success' => false, 'message' => '请描述你的需求'], 422);
        }

        // 前端把已有对话带上,机器人才能记住上文(详见 normalizeHistory 的注释)
        $history = $this->normalizeHistory($request->input('history', []));

        $base  = rtrim((string) env('COZE_BASE_URL', 'http://localhost:8888'), '/');
        $pat   = (string) env('COZE_PAT', '');
        $botId = (string) env('COZE_BOT_ID', '');

        // 机型/技能/配件三个库:本地查询,不走网络
        $catalog = $this->fetchCatalog();
        $isEmpty = ! $catalog['machines'] && ! $catalog['skills'] && ! $catalog['accessories'];

        // 未配置 Coze,或三个库都为空 → 直接降级,不报错
        if ($pat === '' || $botId === '' || $isEmpty) {
            return $this->fallback($request, 'Coze 未配置或商品库为空');
        }

        try {
            $raw = $this->askCoze($base, $pat, $botId, $q, $catalog, $history);
        } catch (\Throwable $e) {
            return $this->fallback($request, $e->getMessage());
        }

        // AI 在末尾附了一段「系统识别用·推荐清单」——解析它,并把这段从给用户看的正文里剥掉
        [$answer, $picks] = $this->splitAnswerAndPicks($raw);

        // 剥掉 AI 偶尔输出的 Markdown 标记:前端是纯文本 text 节点,不渲染 Markdown,
        // 不处理的话页面上会直接显示 ** 和 # 这些符号。
        $answer = $this->stripMarkdown($answer);

        return response()->json([
            'success'      => true,
            'q'            => $q,
            'engine'       => 'coze-v1',
            'answer'       => $answer,
            'intent'       => $this->extractIntent($q),
            'scene'        => null,
            'machines'     => $this->resolveItems($picks['machines'], $catalog['machines'], $answer),
            'skills'       => $this->resolveItems($picks['skills'], $catalog['skills'], $answer),
            'accessories'  => $this->resolveItems($picks['accessories'], $catalog['accessories'], $answer),
            'alternatives' => [],
        ]);
    }

    /**
     * 本地查出机型库,压成给 LLM 的紧凑结构。
     *
     * 关键:把 description 里的 HTML 剥掉、只留纯文本。
     * 实测原始描述里塞着 <img src="..."> 等大段标签,直接喂给模型会显著拖慢推理。
     */
    /**
     * 流式版(POST /api/summon-stream):把 Coze 的 SSE 实时转发给前端。
     *
     * 为什么要这个:非流式版必须等 Coze 出完整答案(8~20 秒)才返回,用户全程只能看转圈。
     * 实测流式下 **0.7 秒**就有第一个片段到达、**3 秒**开始出正文——过程感完全不同。
     *
     * 事件协议(本接口自定义,前端按 event 名分发):
     *   event: meta      data: {q, intent}
     *   event: thinking  data: {text}   ← 模型思考过程(中文),前端折叠展示
     *   event: delta     data: {text}   ← 正文增量
     *   event: done      data: {engine, answer, machines, skills, accessories}
     *   event: error     data: {message}
     *
     * ⚠ 尾部那段「系统识别用·推荐清单」不能流给用户,转发时要提前截断(见 relayCoze)。
     */
    public function summonStream(Request $request)
    {
        @set_time_limit(240);

        $q = trim((string) $request->input('q', ''));

        if ($q === '') {
            return response()->json(['success' => false, 'message' => '请描述你的需求'], 422);
        }

        // 前端把已有对话带上,机器人才能记住上文(详见 normalizeHistory 的注释)
        $history = $this->normalizeHistory($request->input('history', []));

        $base    = rtrim((string) env('COZE_BASE_URL', 'http://localhost:8888'), '/');
        $pat     = (string) env('COZE_PAT', '');
        $botId   = (string) env('COZE_BOT_ID', '');
        $catalog = $this->fetchCatalog();

        return response()->stream(function () use ($q, $base, $pat, $botId, $catalog, $history) {
            // ⚠ 必须先把 PHP 的输出缓冲全部清掉,否则 flush() 推不出去——
            // 实测漏了这步时,整个流会攒到最后一次性到达(客户端看到的就是"卡住然后蹦出来")。
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $emit = function (string $event, array $data) {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $isEmpty = ! $catalog['machines'] && ! $catalog['skills'] && ! $catalog['accessories'];

            if ($pat === '' || $botId === '' || $isEmpty) {
                $emit('error', ['message' => 'Coze 未配置或商品库为空']);

                return;
            }

            $emit('meta', ['q' => $q, 'intent' => $this->extractIntent($q)]);

            try {
                // ⚠ relayCoze 现在返回 [正文, 推荐追问] —— 追问是 Coze 在正文之后另发的
                //   type=follow_up 消息,前端拿它渲染可点击的追问按钮。
                [$raw, $followUps] = $this->relayCoze($base, $pat, $botId, $q, $catalog, $history, $emit);
            } catch (\Throwable $e) {
                $emit('error', ['message' => $e->getMessage()]);

                return;
            }

            [$answer, $picks] = $this->splitAnswerAndPicks($raw);
            $answer = $this->stripMarkdown($answer);

            $emit('done', [
                'engine'      => 'coze-v1',
                'answer'      => $answer,
                'intent'      => $this->extractIntent($q),
                'machines'    => $this->resolveItems($picks['machines'], $catalog['machines'], $answer),
                'skills'      => $this->resolveItems($picks['skills'], $catalog['skills'], $answer),
                'accessories' => $this->resolveItems($picks['accessories'], $catalog['accessories'], $answer),
                // 推荐追问:Coze 生成的可点击问题,前端渲染成气泡下方的追问按钮。
                // ⚠ 去重 + 截断:模型偶发重复,而追问过多会把界面撑满
                'follow_ups'  => array_slice(array_values(array_unique($followUps)), 0, 5),
            ]);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',   // 让 nginx 不要缓冲,否则流式失效
        ]);
    }

    /**
     * 拉 Coze 的 SSE,把正文增量转发出去,返回累计的完整正文。
     *
     * ⚠ 关键:模型会在正文末尾附一段「系统识别用·推荐清单」(【推荐机型】…),
     * 那段不该给用户看。所以:
     *   1. 检测到「【推荐机型】」就停止转发;
     *   2. 平时也预留 12 个字符不发,避免把一个还没写完的标记(比如"【推荐机")漏出去。
     *
     * @return array{0:string,1:string[]} [累计正文, 推荐追问列表]
     */
    private function relayCoze(string $base, string $pat, string $botId, string $q, array $catalog, array $history, callable $emit): array
    {
        // ⚠ 用 PHP **原生 cURL 的 CURLOPT_WRITEFUNCTION**,既不用 Guzzle 也不用 Laravel 的 Http 门面。
        //
        // 实测那两者都会把整个流攒到最后一次性给出(前端看到"卡住几秒→整段蹦出来",流式完全失效):
        //   · Laravel Http 门面:即便加了 stream:true 也一样;
        //   · 原生 Guzzle:$stream->read() 会阻塞到攒够数据才返回
        //     —— 加心跳探针验证过:等待的 3 秒里一个心跳都发不出去,说明卡在 read。
        // cURL 的 WRITEFUNCTION 是**数据一到就回调**,这是 PHP 里做真流式最可靠的方式。
        $full       = '';   // 累计正文(含尾部清单)
        $emittedLen = 0;    // 已转发出去的字符数(按 mb 计)
        $stopped    = false;
        $buffer     = '';
        $followUps  = [];   // 推荐追问(Coze 在正文之后另发的 type=follow_up 消息)

        // 每收到一段数据就解析 SSE,闭包里用引用修改外部变量
        $onData = function ($ch, $data) use (&$full, &$emittedLen, &$stopped, &$buffer, &$followUps, $emit) {
            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (strpos($line, 'data:') !== 0) {
                    continue;
                }

                $payload = trim(substr($line, 5));
                if ($payload === '') {
                    continue;
                }

                $d = json_decode($payload, true);
                if (! is_array($d)) {
                    continue;
                }

                $type = (string) ($d['type'] ?? '');

                // ⚠ 推荐追问:Coze 在正文之后会**另发**若干条 type=follow_up 的消息,
                //   content 就是可点击的追问问题。
                //   原先这里只认 answer,把它们**全丢了** —— 所以前端一直没有任何追问可显示
                //   (而 Coze Studio 的调试预览里是有的)。
                //   ⚠ 它们出现在【推荐机型】清单之后,而那之后的 answer 会被 $stopped 拦掉,
                //     所以这一支必须在下面的 $stopped 判断**之前**收集。
                if ($type === 'follow_up') {
                    $text = trim((string) ($d['content'] ?? ''));

                    if ($text !== '') {
                        $followUps[] = $text;
                    }

                    continue;
                }

                if ($type !== 'answer') {
                    continue;
                }

                // 思考过程(reasoning)走 thinking 事件——前端折叠展示"深度思考"。
                // 实测:思考在 0.5s 就开始流出、持续 3 秒左右,是"过程感"的主要来源。
                $think = (string) ($d['reasoning_content'] ?? '');
                if ($think !== '') {
                    $emit('thinking', ['text' => $think]);
                }

                $delta = (string) ($d['content'] ?? '');
                if ($delta === '') {
                    continue;   // 这一段只有思考,没有正文
                }

                $full .= $delta;

                if ($stopped) {
                    continue;
                }

                if (mb_strpos($full, '【推荐机型】') !== false) {
                    $stopped = true;   // 进入系统清单段,后面的都不发
                    continue;
                }

                // 预留 12 字符不发,避免漏出半个标记
                $safeLen = max(0, mb_strlen($full) - 12);
                if ($safeLen > $emittedLen) {
                    $emit('delta', ['text' => mb_substr($full, $emittedLen, $safeLen - $emittedLen)]);
                    $emittedLen = $safeLen;
                }
            }

            return strlen($data);
        };

        $ch = curl_init($base.'/v3/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => json_encode([
                'bot_id'  => $botId,
                'user_id' => 'summon-ai-'.bin2hex(random_bytes(6)),
                'stream'  => true,
                // ⚠ 历史必须排在当前这句**前面**(保持对话顺序),否则机器人会把先后理解反。
                //   这是"机器人记得住上文"的关键,原因见 normalizeHistory 的注释。
                'additional_messages' => array_merge($history, [[
                    'role'         => 'user',
                    'content'      => $this->buildUserMessage($q, $catalog),
                    'content_type' => 'text',
                ]]),
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER    => [
                'Authorization: Bearer '.$pat,
                'Content-Type: application/json',
            ],
            CURLOPT_WRITEFUNCTION => $onData,
            CURLOPT_TIMEOUT       => 180,
            CURLOPT_HEADER        => false,
        ]);

        curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('Coze 请求失败: '.$curlErr);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('Coze 发起失败 HTTP '.$httpCode);
        }



        if ($full === '') {
            throw new \RuntimeException('Coze 未返回正文');
        }

        return [$full, $followUps];
    }

    /**
     * 本地查出三个库(机型/技能/配件),压成给 LLM 的紧凑结构。
     *
     * 返回: ['machines'=>[...], 'skills'=>[...], 'accessories'=>[...]]
     *
     * 关键:把 description 里的 HTML 剥掉、只留纯文本。
     * 实测原始描述里塞着 <img src="..."> 等大段标签,直接喂给模型会显著拖慢推理。
     */
    private function fetchCatalog(): array
    {
        $repo    = app(\Webkul\Product\Repositories\ProductRepository::class);
        $channel = core()->getCurrentChannel()->id;

        $catalog = ['machines' => [], 'skills' => [], 'accessories' => []];
        $seen    = [];

        foreach (self::TYPE_CATEGORIES as $type => $slugs) {
            // 逐个 slug 查——这样能记住每件商品属于哪个分类(租用/购买/技能/配件),
            // AI 需要知道"这台是租还是买"才能给出可执行的建议。
            foreach ($slugs as $slug) {
                $categoryId = \Webkul\Category\Models\Category::query()
                    ->whereHas('translations', fn ($t) => $t->where('slug', $slug))
                    ->value('id');

                if (! $categoryId) {
                    continue;
                }

                $products = $repo->setSearchEngine('database')->getAll([
                    'category_id'          => $categoryId,
                    'limit'                => self::CATALOG_LIMIT,
                    'page'                 => 1,
                    'channel_id'           => $channel,
                    'status'               => 1,
                    'visible_individually' => 1,
                ]);

                foreach ($products as $product) {
                    $id = (int) $product->id;
                    // 同一商品只归一类(避免既算机型又算配件)
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;

                    $name = (string) ($product->name ?? '');
                    if ($name === '') {
                        continue;
                    }

                    // ⚠ 价格必须走 getTypeInstance()->getMinimalPrice():
                    // $product->min_price 属性在这个上下文里是空的(实测全为"未标价")。
                    // 与 ProductResource 的取价方式保持一致。
                    try {
                        $price = core()->formatPrice($product->getTypeInstance()->getMinimalPrice());
                    } catch (\Throwable) {
                        $price = '';
                    }

                    $catalog[$type][] = [
                        'id'    => $id,
                        'name'  => $name,
                        'kind'  => $slug,   // rent / buy / skill / accessory
                        'price' => (string) $price,
                        'scene' => $this->plainText((string) ($product->description ?? '')),
                    ];
                }
            }
        }

        return $catalog;
    }

    /**
     * 把商品描述转成纯文本:剥 HTML、压空白、截断。
     */
    private function plainText(string $html): string
    {
        $txt = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = trim((string) preg_replace('/\s+/u', ' ', $txt));

        return mb_substr($txt, 0, 200);
    }

    /**
     * 把机型库拼成紧凑文本,连同用户需求一起发给 Coze Agent。
     */
    /**
     * 把前端传来的对话历史规整成 Coze 的 additional_messages 格式。
     *
     * ── 为什么必须带历史(2026-09-29 实测) ──
     *   本项目原先的 Coze 调用**每轮都开新会话**,两个原因叠加:
     *     ① 请求里不带 conversation_id → 服务端当然当成新会话;
     *     ② 而**带上也没用** —— 实测这个自建版会**忽略**请求里的 conversation_id,
     *        返回的 conversation_id 每轮都不同(传了也白传)。
     *   后果是机器人**完全记不住上一轮**:每轮都从开场白重来、反复问同一个问题
     *   (用户看到的现象就是"重复回答")。
     *
     *   所以改成**把历史随请求带上**,不依赖服务端会话状态。实测有效:
     *   带上历史后,第二轮能正确复述第一轮说过的"电力变电站"与"二十万预算"。
     *
     * ⚠ 必须限长:历史越长 token 与延迟越高,而且旧内容会稀释当前意图。
     *   这里取最近 10 条、总量 3000 字,且**从最近往前**累加 ——
     *   保证被丢掉的永远是最早的内容,留下的总是最新上下文。
     *
     * @param  mixed  $raw  前端的 history,形如 [{role:'user'|'ai', content:'...'}]
     * @return array<int, array{role:string, content:string, content_type:string}>
     */
    private function normalizeHistory(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (array_slice($raw, -10) as $item) {
            if (! is_array($item)) {
                continue;
            }

            // ⚠ 两种字段名都收:约定的线上格式是 `content`(和 Coze 一致),但前端的
            //   消息对象里本来叫 `text`。只认一种的话,字段名一旦对不上,历史会**静默变空**
            //   —— 表现为"机器人又失忆了",而且不报任何错,极难查。
            $content = trim((string) ($item['content'] ?? $item['text'] ?? ''));

            if ($content === '') {
                continue;
            }

            // ⚠ 前端用 'ai' 标记机器人,Coze 要求 'assistant' —— 直接透传会被判为非法角色
            $role = ($item['role'] ?? '') === 'user' ? 'user' : 'assistant';

            $out[] = ['role' => $role, 'content' => $content, 'content_type' => 'text'];
        }

        $kept  = [];
        $chars = 0;

        foreach (array_reverse($out) as $message) {
            $length = mb_strlen($message['content']);

            if ($kept !== [] && $chars + $length > 3000) {
                break;
            }

            $chars += $length;
            array_unshift($kept, $message);
        }

        return $kept;
    }

    private function buildUserMessage(string $q, array $catalog): string
    {
        $sections = [];

        foreach (self::TYPE_LABELS as $type => $label) {
            $items = $catalog[$type] ?? [];
            if (! $items) {
                continue;
            }

            $lines = [];
            foreach ($items as $i => $m) {
                // 只有机型需要标「租用/购买」——这决定建议能不能落地执行
                $kind = ['rent' => '租用', 'buy' => '购买'][$m['kind'] ?? ''] ?? '';
                $lines[] = sprintf(
                    '%d. 名称:「%s」%s 价格:%s 说明:%s',
                    $i + 1,
                    $m['name'],
                    $kind !== '' ? ' 方式：'.$kind : '',
                    $m['price'] !== '' ? $m['price'] : '未标价',
                    $m['scene'] !== '' ? $m['scene'] : '(无说明)'
                );
            }

            $sections[] = sprintf('【%s】共 %d 项(价格单位为元):', $label, count($items))
                ."\n".implode("\n", $lines);
        }

        return implode("\n\n", $sections)."\n\n【用户需求】".$q;
    }

    /**
     * 调 Coze /v3/chat(异步) → 轮询取回复。
     */
    private function askCoze(string $base, string $pat, string $botId, string $q, array $catalog, array $history = []): string
    {
        $http = Http::withToken($pat)->timeout(30);

        $start = $http->post($base.'/v3/chat', [
            'bot_id'  => $botId,
            // ⚠ user_id 每次唯一:实测固定同一 user_id 时历史请求会互相阻塞
            'user_id' => 'summon-ai-'.bin2hex(random_bytes(6)),
            'stream'  => false,
            // ⚠ 历史排在当前这句前面(保持对话顺序)—— 机器人靠它记住上文,见 normalizeHistory
            'additional_messages' => array_merge($history, [[
                'role'         => 'user',
                'content'      => $this->buildUserMessage($q, $catalog),
                'content_type' => 'text',
            ]]),
        ]);

        if (! $start->successful()) {
            throw new \RuntimeException('Coze 发起失败 HTTP '.$start->status());
        }

        $data   = $start->json('data') ?? [];
        $chatId = $data['id'] ?? '';
        $convId = $data['conversation_id'] ?? '';

        if ($chatId === '' || $convId === '') {
            throw new \RuntimeException('Coze 未返回 chat_id');
        }

        // 轮询:每 2 秒一次,最多约 100 秒
        $status = 'in_progress';
        for ($i = 0; $i < 50; $i++) {
            usleep(2000000);

            $r = Http::withToken($pat)->timeout(20)->get($base.'/v3/chat/retrieve', [
                'conversation_id' => $convId,
                'chat_id'         => $chatId,
            ]);

            if (! $r->successful()) {
                throw new \RuntimeException('Coze 轮询失败 HTTP '.$r->status());
            }

            $status = $r->json('data.status') ?? '';
            if (in_array($status, ['completed', 'failed', 'requires_action'], true)) {
                break;
            }
        }

        if ($status !== 'completed') {
            throw new \RuntimeException('Coze 未完成,status='.$status);
        }

        $m = Http::withToken($pat)->timeout(20)->get($base.'/v3/chat/message/list', [
            'conversation_id' => $convId,
            'chat_id'         => $chatId,
        ]);

        if (! $m->successful()) {
            throw new \RuntimeException('Coze 取消息失败 HTTP '.$m->status());
        }

        $parts = [];
        foreach (($m->json('Messages') ?? []) as $msg) {
            if (($msg['role'] ?? '') === 'assistant' && ($msg['type'] ?? '') === 'answer') {
                $parts[] = (string) ($msg['content'] ?? '');
            }
        }

        $answer = trim(implode("\n", $parts));

        if ($answer === '') {
            throw new \RuntimeException('Coze 返回内容为空');
        }

        return $answer;
    }

    /**
     * 把 AI 回复拆成「给用户看的正文」和「系统识别用的推荐清单」。
     *
     * AI 被要求在回复最末尾附上(格式见 Agent 提示词):
     *   【推荐机型】name1、name2
     *   【推荐技能】...
     *   【推荐配件】...
     * 这段只给系统用,必须从正文里剥掉——用户不该看到它。
     *
     * @return array{0:string,1:array<string,string[]>} [正文, ['machines'=>[名称...], 'skills'=>[...], 'accessories'=>[...]]]
     */
    private function splitAnswerAndPicks(string $raw): array
    {
        $picks = ['machines' => [], 'skills' => [], 'accessories' => []];

        // 以「【推荐机型】」为分界点:之前是正文,之后是清单
        $pos = mb_strpos($raw, '【推荐机型】');
        if ($pos === false) {
            // 模型没按格式输出 → 正文原样返回,推荐项留空(前端不会误渲染卡片)
            return [trim($raw), $picks];
        }

        $answer = trim(mb_substr($raw, 0, $pos));
        $block  = mb_substr($raw, $pos);

        $markers = [
            '【推荐机型】' => 'machines',
            '【推荐技能】' => 'skills',
            '【推荐配件】' => 'accessories',
        ];

        foreach ($markers as $marker => $key) {
            if (! preg_match('/'.preg_quote($marker, '/').'\s*([^\n]*)/u', $block, $m)) {
                continue;
            }

            $val = trim($m[1]);
            if ($val === '' || $val === '无' || $val === '暂无') {
                continue;
            }

            $names = preg_split('/[、,，;；\/]+/u', $val) ?: [];
            $picks[$key] = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== '' && $n !== '无'));
        }

        return [$answer, $picks];
    }

    /**
     * 剥掉 AI 回复里的 Markdown 标记。
     *
     * 前端用 <text> 纯文本渲染,不解析 Markdown;不处理的话用户会看到
     * `**加粗**`、`## 标题`、`` `代码` `` 这类原始符号。
     */
    private function stripMarkdown(string $t): string
    {
        $t = (string) preg_replace('/\*\*(.+?)\*\*/us', '$1', $t);   // **加粗** → 加粗
        $t = (string) preg_replace('/^#{1,6}\s*/mu', '', $t);        // ## 标题 → 标题
        $t = (string) preg_replace('/`([^`]+)`/u', '$1', $t);        // `代码` → 代码
        $t = (string) preg_replace('/^\s*[-*]\s+/mu', '· ', $t);     // 列表符号统一成 ·

        return trim($t);
    }

    /**
     * 按「推荐清单里的名称」挑出库中条目(机型/技能/配件通用)。
     *
     * 用**精确名称匹配**而非全文子串:清单是模型从库里原样复制的,
     * 名称对不上就不返回——保证返回给前端的始终是库里真实存在的条目。
     * match_reason 仍从正文里取该条目所在的那句话,便于前端展示与人工抽检。
     */
    private function resolveItems(array $pickedNames, array $items, string $answer): array
    {
        if (! $pickedNames) {
            return [];
        }

        $out = [];
        foreach ($items as $m) {
            $hit = false;
            foreach ($pickedNames as $picked) {
                if ($picked === $m['name']) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                continue;
            }

            $out[] = [
                'id'           => $m['id'],
                'name'         => $m['name'],
                'min_price'    => $m['price'],
                'match_reason' => $this->reasonFor($answer, $m['name']),
            ];
        }

        return $out;
    }

    /**
     * 抽出 AI 回复中该机型所在的那句话,作为推荐理由(便于前端展示与人工抽检)。
     */
    private function reasonFor(string $answer, string $name): string
    {
        foreach (preg_split('/[\n。]/u', $answer) ?: [] as $line) {
            if (mb_stripos($line, $name) !== false) {
                return trim($line, " \t\n\r\0\x0B-*#");
            }
        }

        return '';
    }

    /**
     * 粗提意图(预算)。仅做展示用,不参与筛选——语义筛选由 AI 完成。
     */
    private function extractIntent(string $q): array
    {
        $budget = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(万|w|W)/u', $q, $m)) {
            $budget = (float) $m[1] * 10000;
        } elseif (preg_match('/(\d{4,})/u', $q, $m)) {
            $budget = (float) $m[1];
        }

        return ['budget_max' => $budget];
    }

    /**
     * 降级:AI 不可用时回退 rule-v1,保证接口始终有响应。
     */
    private function fallback(Request $request, string $why): JsonResponse
    {
        $res  = app(SummonController::class)->summon($request);
        $body = $res->getData(true);
        $body['engine']   = 'rule-v1-fallback';
        $body['ai_error'] = $why;

        return response()->json($body);
    }
}
