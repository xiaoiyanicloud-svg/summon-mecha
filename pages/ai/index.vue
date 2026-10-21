<template>
    <view class="page-container" @keydown.esc.prevent="closeDrawer">
    <!-- 背景图：微信 WXSS 无法用本地图作 page 的 background，故用 image 标签全屏垫底,内容在 z-index:0 之上 -->
    <image class="page-bg" src="/static/AIxiaozhao.jpg" mode="aspectFill"></image>
    <!-- ===== 顶部导航区 ===== -->
    <view class="nav-area">
      <view class="nav-content">
        <view class="nav-left">
          <text class="brand">AI小召</text>
          <text class="subtitle">懂机器.更懂你</text>
        </view>
        <view class="nav-right">
          <text
            class="menu-icon"
            role="button"
            tabindex="0"
            aria-label="打开历史对话"
            @tap="toggleDrawer"
            @keydown.enter.prevent="toggleDrawer"
          >☰</text>
        </view>
      </view>
    </view>

    <!-- ===== 对话区(可滚动,一问一答) ===== -->
    <scroll-view class="chat-area" role="log" aria-label="与小召的对话" scroll-y="true" show-scrollbar="false" :scroll-into-view="scrollAnchor" scroll-with-animation="true" @scroll="onChatScroll">
      <!-- 空态:欢迎卡片(有对话后自动隐藏) -->
      <view class="main-card" v-if="messages.length === 0">
        <view class="card-section">
          <view class="card-header">
            <view class="card-heading">
              <text class="card-title"><text class="icon-sparkle">✨</text> 一句话，帮你选对机器人</text>
              <text class="card-subtitle">说清场景、任务和预算，小召给你选型与落地建议</text>
            </view>
          </view>
          <view class="question-btns">
            <text class="question-label">不知道怎么描述？可以从这里开始</text>
            <view class="q-btn" role="button" tabindex="0" aria-label="询问工厂分拣机器人怎么选型" @tap="onQuestionTap('工厂分拣机器人怎么选型？请结合节拍、精度和预算给建议')" @keydown.enter.prevent="onQuestionTap('工厂分拣机器人怎么选型？请结合节拍、精度和预算给建议')">
              <text>工厂分拣怎么选型</text>
              <text class="q-arrow">›</text>
            </view>
            <view class="q-btn" role="button" tabindex="0" aria-label="询问酒店配送机器人怎么部署" @tap="onQuestionTap('酒店配送机器人怎么部署？需要考虑楼层、电梯和高峰期')" @keydown.enter.prevent="onQuestionTap('酒店配送机器人怎么部署？需要考虑楼层、电梯和高峰期')">
              <text>酒店配送怎么落地</text>
              <text class="q-arrow">›</text>
            </view>
            <view class="q-btn" role="button" tabindex="0" aria-label="询问展会接待机器人租用报价" @tap="onQuestionTap('展会接待机器人租一周大概怎么报价？需要迎宾和互动')" @keydown.enter.prevent="onQuestionTap('展会接待机器人租一周大概怎么报价？需要迎宾和互动')">
              <text>展会租用怎么报价</text>
              <text class="q-arrow">›</text>
            </view>
          </view>
        </view>

        <view
          class="quick-filter-toggle"
          role="button"
          tabindex="0"
          :aria-expanded="quickFilterExpanded ? 'true' : 'false'"
          @tap="toggleQuickFilter"
          @keydown.enter.prevent="toggleQuickFilter"
        >
          <view class="quick-filter-copy">
            <text class="quick-filter-title">按预算和任务筛选</text>
            <text class="quick-filter-sub">已有明确条件时再展开</text>
          </view>
          <text class="quick-filter-arrow">{{ quickFilterExpanded ? '收起 ↑' : '展开 ↓' }}</text>
        </view>

        <view class="quick-filter-panel" v-if="quickFilterExpanded">
          <view class="tags-row">
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('3万以下') ? 'true' : 'false'" :class="{ active: isTagSelected('3万以下') }" @tap="onTagClick('3万以下')" @keydown.enter.prevent="onTagClick('3万以下')">3万以下</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('3-10万') ? 'true' : 'false'" :class="{ active: isTagSelected('3-10万') }" @tap="onTagClick('3-10万')" @keydown.enter.prevent="onTagClick('3-10万')">3-10万</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('10-30万') ? 'true' : 'false'" :class="{ active: isTagSelected('10-30万') }" @tap="onTagClick('10-30万')" @keydown.enter.prevent="onTagClick('10-30万')">10-30万</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('30万以上') ? 'true' : 'false'" :class="{ active: isTagSelected('30万以上') }" @tap="onTagClick('30万以上')" @keydown.enter.prevent="onTagClick('30万以上')">30万以上</text>
          </view>
          <view class="tags-row" style="margin-top: 12rpx;">
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('分拣') ? 'true' : 'false'" :class="{ active: isTagSelected('分拣') }" @tap="onTagClick('分拣')" @keydown.enter.prevent="onTagClick('分拣')">分拣</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('搬运') ? 'true' : 'false'" :class="{ active: isTagSelected('搬运') }" @tap="onTagClick('搬运')" @keydown.enter.prevent="onTagClick('搬运')">搬运</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('焊接') ? 'true' : 'false'" :class="{ active: isTagSelected('焊接') }" @tap="onTagClick('焊接')" @keydown.enter.prevent="onTagClick('焊接')">焊接</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('装配') ? 'true' : 'false'" :class="{ active: isTagSelected('装配') }" @tap="onTagClick('装配')" @keydown.enter.prevent="onTagClick('装配')">装配</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('检测') ? 'true' : 'false'" :class="{ active: isTagSelected('检测') }" @tap="onTagClick('检测')" @keydown.enter.prevent="onTagClick('检测')">检测</text>
            <text class="tag" role="button" tabindex="0" :aria-pressed="isTagSelected('包装') ? 'true' : 'false'" :class="{ active: isTagSelected('包装') }" @tap="onTagClick('包装')" @keydown.enter.prevent="onTagClick('包装')">包装</text>
          </view>
          <view
            class="quick-start"
            :class="{ disabled: !hasQuickSelection }"
            role="button"
            tabindex="0"
            :aria-label="hasQuickSelection ? '按已选条件开始匹配' : '请先选择预算或任务'"
            @tap="startQuickMatch"
            @keydown.enter.prevent="startQuickMatch"
          >
            <text>{{ hasQuickSelection ? '按已选条件开始匹配' : '先选预算或任务，再开始匹配' }}</text>
            <text class="quick-start-arrow">→</text>
          </view>
        </view>
      </view>

      <!-- ===== 消息流:一问一答 ===== -->
      <view v-for="(m, i) in messages" :key="i" :id="'msg-' + i" class="msg" :class="m.role">
        <!-- 深度思考:可折叠,内容实时流入(仅 AI 消息) -->
        <view class="think" v-if="m.role === 'ai' && m.thinking">
          <view
            class="think-head"
            role="button"
            tabindex="0"
            :aria-expanded="m.thinkingOpen ? 'true' : 'false'"
            @tap="m.thinkingOpen = !m.thinkingOpen"
            @keydown.enter.prevent="m.thinkingOpen = !m.thinkingOpen"
          >
            <text class="think-title">{{ m.thinkingOpen ? '💭 正在思考…' : '💭 已深度思考' }}</text>
            <text class="think-toggle">{{ m.thinkingOpen ? '收起' : '展开' }}</text>
          </view>
          <view class="think-body" v-if="m.thinkingOpen">
            <text class="think-text">{{ m.thinking }}</text>
          </view>
        </view>

        <view class="bubble" :class="m.role" v-if="m.text">
          <text class="bubble-text">{{ m.text }}</text>
        </view>

        <!-- 朗读:AI 且有正文才显示。图标沿用项目既有习惯用 emoji 字形
             (static/ 里没有喇叭图标,页面里的 💭 🎯 📋 💬 ➤ ✕ ✨ 都是 emoji) -->
        <view class="answer-actions" v-if="m.role === 'ai' && m.text">
          <view
            class="speak"
            role="button"
            tabindex="0"
            :aria-label="speakLabel(i)"
            @tap="onSpeakTap(i)"
            @keydown.enter.prevent="onSpeakTap(i)"
          >
            <text class="speak-text">{{ speakLabel(i) }}</text>
          </view>
          <view
            class="copy-answer"
            role="button"
            tabindex="0"
            aria-label="复制回答"
            @tap="onCopyAnswer(i)"
            @keydown.enter.prevent="onCopyAnswer(i)"
          >
            <text class="speak-text">📋 复制</text>
          </view>
        </view>

        <!-- 请求过程与失败恢复：停止不能只做视觉按钮，必须给出后续动作 -->
        <view class="reply-state" role="status" aria-live="polite" v-if="m.role === 'ai' && m.generating">
          <text class="reply-state-dot"></text>
          <text class="reply-state-text">{{ replyStateText }}</text>
          <text class="reply-state-action" @tap.stop="stopGeneration">停止</text>
        </view>
        <view class="reply-state stopped" v-else-if="m.role === 'ai' && m.interrupted">
          <text class="reply-state-text">已停止生成</text>
          <text class="reply-state-action" @tap.stop="retryMessage(m.retryPrompt)">重新生成</text>
        </view>
        <view class="reply-state error" v-else-if="m.role === 'ai' && m.error">
          <text class="reply-error-text">{{ m.error }}</text>
          <view class="reply-error-actions">
            <view class="reply-error-btn" @tap.stop="retryMessage(m.retryPrompt)">重试</view>
            <view class="reply-error-btn primary" @tap.stop="openCustomPlan(m.retryPrompt)">交给方案专家</view>
          </view>
        </view>

        <!-- AI 消息附带的推荐卡片 -->
        <view v-if="m.role === 'ai' && m.text" class="picks"><button class="summon-scene" @tap="createSceneRequest(i)">整理本次需求，继续人工评估与正式报价</button></view>
        <view class="picks" v-if="m.role === 'ai' && m.scene" @tap="goSceneDetail(m.scene)">
          <view class="summon-scene">
            <text class="summon-scene-icon">{{ m.scene.icon || '🎯' }}</text>
            <view class="summon-scene-info">
              <text class="summon-scene-name">{{ m.scene.name }}</text>
              <text class="summon-scene-desc">{{ m.scene.description }}</text>
            </view>
            <text class="summon-scene-go">查看方案 ›</text>
          </view>
        </view>

        <view class="picks" v-if="m.role === 'ai' && m.machines && m.machines.length">
          <text class="summon-sub">推荐机型（点击查看详情）</text>
          <view class="summon-machine" v-for="x in m.machines" :key="x.id" @tap="goItemDetail(x, 'machine')">
            <view class="summon-machine-info">
              <text class="summon-machine-name">{{ x.name }}</text>
              <text class="summon-machine-reason" v-if="x.match_reason">{{ x.match_reason }}</text>
            </view>
            <text class="summon-machine-price" v-if="x.prices && x.prices.final">¥{{ x.prices.final.price }}/天起</text>
            <text class="summon-machine-price" v-else-if="x.min_price">{{ x.min_price }}</text>
          </view>
        </view>

        <view class="picks" v-if="m.role === 'ai' && m.skills && m.skills.length">
          <text class="summon-sub">推荐技能（点击查看详情）</text>
          <view class="summon-machine" v-for="x in m.skills" :key="'sk-' + x.id" @tap="goItemDetail(x, 'skill')">
            <text class="summon-machine-name">{{ x.name }}</text>
            <text class="summon-machine-price" v-if="x.min_price">{{ x.min_price }}</text>
          </view>
        </view>

        <view class="picks" v-if="m.role === 'ai' && m.accessories && m.accessories.length">
          <text class="summon-sub">推荐配件（点击查看详情）</text>
          <view class="summon-machine" v-for="x in m.accessories" :key="'ac-' + x.id" @tap="goItemDetail(x, 'accessory')">
            <text class="summon-machine-name">{{ x.name }}</text>
            <text class="summon-machine-price" v-if="x.min_price">{{ x.min_price }}</text>
          </view>
        </view>

        <!-- 推荐追问:Coze 在正文之后另发的 follow_up 消息,点一下即作为下一句发出 -->
        <view class="followups" v-if="m.role === 'ai' && m.followUps && m.followUps.length">
          <view
            class="followup"
            v-for="(fq, fi) in m.followUps"
            :key="'fq-' + fi"
            @tap="onQuestionTap(fq)"
          >
            <text class="followup-text">{{ fq }}</text>
          </view>
        </view>

        <!-- 无匹配或用户需要人工确认时，不把高意向需求留在死路上 -->
        <view class="custom-cta-card" v-if="m.role === 'ai' && m.customCta" @tap="openCustomPlan(m.retryPrompt)">
          <view class="custom-cta-copy">
            <text class="custom-cta-title">需要按你的场地和预算出方案？</text>
            <text class="custom-cta-sub">把这次需求交给方案专家，1 小时内联系</text>
          </view>
          <text class="custom-cta-arrow">›</text>
        </view>
      </view>

      <!-- 思考中提示 -->
      <view class="msg ai" role="status" aria-live="polite" v-if="requestState === 'connecting'">
        <view class="bubble ai bubble-thinking">
          <text class="bubble-text">正在连接小召…</text>
        </view>
      </view>

      <view class="chat-tail" id="chat-tail"></view>
    </scroll-view>

    <view
      class="new-reply-tip"
      role="button"
      tabindex="0"
      aria-label="回到最新回答"
      v-if="newReplyAvailable"
      @tap="scrollToBottom(true)"
      @keydown.enter.prevent="scrollToBottom(true)"
    >
      <text>↓ 回到最新回答</text>
    </view>

    <!-- ===== 底部输入区域 ===== -->
    <view class="input-area">
      <!-- 用 textarea + auto-height:长句会随行数把输入框撑高,而不是挤在一行看不到 -->
      <view class="input-main">
        <textarea
          class="input-box"
          :placeholder="inputPlaceholder"
          confirm-type="send"
          :maxlength="inputMaxLength"
          auto-height
          v-model="inputValue"
          aria-label="描述你的需求"
          @input="onDraftInput"
          @confirm="onSendMsg"
        />
        <text class="input-count" v-if="inputValue.length > 1600">{{ inputValue.length }}/{{ inputMaxLength }}</text>
      </view>
      <!-- 麦克风图标：按住说话（松开转文字填进输入框，不自动发送） -->
      <image
        class="mic-icon" :class="{ 'mic-recording': voice.visible, 'mic-disabled': isGenerating }"
        src="/static/maikefeng.png" 
        mode="aspectFit" 
        aria-label="按住说话"
        @touchstart="onVoiceStart"
        @touchmove="onVoiceMove"
        @touchend="onVoiceEnd"
        @touchcancel="onVoiceCancel"
      ></image>
      <view
        class="send-icon"
        :class="{ disabled: !canSend, stop: isGenerating }"
        role="button"
        tabindex="0"
        :aria-label="isGenerating ? '停止生成' : '发送需求'"
        @tap="onPrimaryAction"
        @keydown.enter.prevent="onPrimaryAction"
      >
        <text>{{ isGenerating ? '■' : '➤' }}</text>
      </view>
    </view>

    <!-- ===== 自定义底部导航栏 ===== -->
    <CustomTabBar currentPage="/pages/ai/index" />

    <!-- ===== 历史对话侧边栏 ===== -->
    <view class="drawer-mask" v-if="drawerVisible" @tap="closeDrawer"></view>
    <view class="drawer open" v-if="drawerVisible" role="dialog" aria-modal="true" aria-label="历史对话">
      <view class="drawer-header">
        <text class="drawer-title">📋 历史对话</text>
        <view class="drawer-actions">
          <text class="drawer-new" role="button" tabindex="0" aria-label="新建对话" @tap="newConversation" @keydown.enter.prevent="newConversation">＋ 新对话</text>
          <text class="drawer-close" role="button" tabindex="0" aria-label="关闭历史对话" @tap="closeDrawer" @keydown.enter.prevent="closeDrawer">✕</text>
        </view>
      </view>
      <view class="drawer-summary" v-if="conversations.length || loggedIn" aria-live="polite">
        <text class="drawer-local">{{ syncSummaryText }}</text>
        <view class="drawer-sync-actions">
          <text v-if="loggedIn" class="drawer-sync" role="button" tabindex="0" aria-label="同步云端历史对话" @tap="manualSync" @keydown.enter.prevent="manualSync">{{ syncState === 'syncing' ? '同步中…' : '同步' }}</text>
          <text v-if="conversations.length" class="drawer-clear" role="button" tabindex="0" aria-label="清空全部历史对话" @tap="clearConversations" @keydown.enter.prevent="clearConversations">清空全部</text>
        </view>
      </view>
      <view class="drawer-search">
        <text class="drawer-search-icon">🔎</text>
        <input
          class="drawer-search-input"
          v-model="historyQuery"
          :focus="historySearchFocused"
          placeholder="搜索历史对话"
          confirm-type="search"
          aria-label="搜索历史对话"
          @confirm="onHistorySearchConfirm"
        />
        <text class="drawer-search-clear" v-if="historyQuery" role="button" tabindex="0" aria-label="清除搜索" @tap="historyQuery = ''">✕</text>
      </view>
      <scroll-view class="drawer-list" scroll-y="true" show-scrollbar="false">
        <view
          class="drawer-item"
          v-for="item in filteredConversations"
          :key="item.id"
          role="button"
          tabindex="0"
          :aria-label="'打开对话：' + item.title"
          @tap="loadHistory(item)"
          @keydown.enter.prevent="loadHistory(item)"
        >
          <view class="drawer-item-left">
            <text class="drawer-item-icon">💬</text>
            <view class="drawer-item-info">
              <text class="drawer-item-title">{{ item.title }}</text>
              <text class="drawer-item-time">{{ item.time }}</text>
            </view>
          </view>
          <view class="drawer-item-right">
            <text
              class="drawer-item-delete"
              role="button"
              tabindex="0"
              :aria-label="'删除对话：' + item.title"
              @tap.stop="deleteConversation(item)"
              @keydown.enter.stop.prevent="deleteConversation(item)"
            >删除</text>
            <text class="drawer-item-arrow">›</text>
          </view>
        </view>
        <view class="drawer-empty" v-if="conversations.length === 0">
          <text class="drawer-empty-icon">📭</text>
          <text class="drawer-empty-text">暂无历史对话</text>
          <text class="drawer-empty-sub">对话只保存在当前设备</text>
        </view>
        <view class="drawer-empty" v-else-if="filteredConversations.length === 0">
          <text class="drawer-empty-icon">🔍</text>
          <text class="drawer-empty-text">没有匹配的对话</text>
          <text class="drawer-empty-sub">换个关键词试试</text>
        </view>
        <view style="height: 60rpx;"></view>
      </scroll-view>
    </view>

    <view class="sr-only" role="status" aria-live="polite">{{ syncAnnouncement }}</view>

    <!-- ===== 录音浮层（按住说话时显示）===== -->
    <view class="voice-mask" v-if="voice.visible">
      <view class="voice-card">
        <!-- 音量条：H5 有真实音量驱动；小程序端没有音量回调，退化为纯装饰动画 -->
        <view class="voice-wave" :class="{ 'is-animated': !voice.level }">
          <view
            v-for="(h, i) in waveBars"
            :key="i"
            class="voice-bar"
            :style="voice.level ? { height: h + 'rpx' } : {}"
          ></view>
        </view>

        <!-- 实时文字：只有「小程序 + 同声传译插件」这条链路会有内容 -->
        <text class="voice-live" :class="{ 'is-empty': !voice.partial }">
          {{ voice.partial || (voice.realtime ? '请说话…' : '正在录音…') }}
        </text>

        <text class="voice-timer">{{ voiceTimerText }}</text>

        <text class="voice-hint" :class="{ 'is-cancel': voice.willCancel }">
          {{ voiceHintText }}
        </text>
      </view>
    </view>
  </view>
</template>

<script>
import CustomTabBar from '@/components/CustomTabBar.vue';
import { summonApi, summonStream, aiConversationApi } from '@/utils/api';
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config';
import { trackAiEvent, flushAiEvents } from '@/utils/ai-analytics';
import { openSceneRequest, sceneDraftPayload } from '@/utils/scene-request';
import {
  createVoiceSession,
  isVoiceSupported,
  isRealtime,
  AUTO_SEND_AFTER_ASR,
  ERR,
  ERR_TEXT
} from '@/utils/voice';
// ⚠ 必须起别名:本页已经从 utils/voice 导入了 ERR / ERR_TEXT(语音输入的错误码表),
//   而朗读用的是另一套表。同名导入会直接导致构建失败(Duplicate declaration)。
import { createTtsSession, ERR as TTS_ERR, ERR_TEXT as TTS_ERR_TEXT } from '@/utils/tts';

/**
 * 当前语音会话实例。
 *
 * ⚠ 刻意**不放进 data**：会话里持有 MediaRecorder / MediaStream 这类原生对象，
 * 一旦被 Vue 的响应式代理包住，调用其方法会抛 "Illegal invocation"。
 * 本页同一时刻只会有一个语音会话，所以用模块级变量最简单也最安全。
 */
let voiceSession = null;
let voiceTimerId = null;

/**
 * 当前朗读会话实例。
 *
 * ⚠ 同样刻意**不放进 data**：朗读会话内部持有 InnerAudioContext，也是原生对象，
 * 进响应式代理一样会 "Illegal invocation"。与 voiceSession 同款处理。
 */
let ttsSession = null;
let activeSummonCancel = null;
let activeSummonSeq = 0;
let draftSaveTimer = null;
let syncTimer = null;
let syncSeq = 0;

function createConversationClientId() {
  const random = Math.random().toString(36).slice(2, 10);
  return `conv_${Date.now().toString(36)}_${random}`;
}

function formatConversationTime(timestamp) {
  const date = new Date(Number(timestamp) || Date.now());
  const p = n => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())} ${p(date.getHours())}:${p(date.getMinutes())}`;
}

function customerStorageScope(customer) {
  const id = customer && customer.id ? String(customer.id) : '';
  if (id) return `user_${id}`;
  const seed = String((customer && customer.email) || 'authenticated');
  let hash = 2166136261;
  for (let i = 0; i < seed.length; i++) {
    hash ^= seed.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  return `user_h${(hash >>> 0).toString(16)}`;
}

export default {
  components: {
    CustomTabBar
  },
  data() {
    return {
      inputValue: '',
      drawerVisible: false,
      // 小召·召唤对话流:一问一答,每条 { role:'user'|'ai', text, machines?, skills?, accessories?, scene? }
      messages: [],
      // idle / connecting / thinking / streaming / error
      requestState: 'idle',
      activeAiIndex: -1,
      lastUserPrompt: '',
      inputMaxLength: 2000,
      // 滚到底用的锚点 id(scroll-into-view)
      scrollAnchor: '',
      followLatest: true,
      newReplyAvailable: false,
      chatScrollTop: 0,
      chatScrollHeight: 0,
      chatViewportHeight: 0,
      quickBudget: '',
      quickTasks: [],
      quickFilterExpanded: false,
      draftRestored: false,
      // 真实历史对话(本地持久化):[{ id, title, time, messages:[...] }]
      // 原来是 6 条写死的假数据,点了没反应(死数据)。
      conversations: [],
      // 当前会话 id;null 表示新会话(下次发送时创建)
      currentConvId: null,
      historyQuery: '',
      historySearchFocused: false,
      loggedIn: false,
      accountScope: 'guest',
      syncState: 'idle',
      lastSyncAt: '',
      syncAnnouncement: '',
      conversationTombstones: [],

      // ===== 语音输入(按住说话)=====
      // ⚠ 会话实例本身放在模块级变量里，不放这里(原生对象不能进响应式代理)
      voice: {
        visible: false,     // 录音浮层是否显示
        partial: '',        // 实时识别的中间文字(只有「小程序+插件」链路会有)
        realtime: false,    // 当前是否"边说边出字"，决定浮层提示语
        willCancel: false,  // 手指上滑到取消区
        level: 0,           // 0~1 音量(仅 H5 有真实值)
        willCancel: false   // 手指上滑到取消区
      },
      voiceElapsed: 0,      // 已录时长(毫秒)，用于浮层计时
      voiceHinted: false,   // 是否已经教过用户"按住说话"的手势

      // ===== 朗读(TTS)=====
      // ⚠ 播放态**绝不能写进 message 对象**：persistCurrentConversation() 用
      //   this.messages.slice() 整体落盘，写进去会被一起持久化到 storage，
      //   下次 loadHistory 读回来时状态是脏的。所以放页面级。
      tts: {
        activeIndex: -1,    // 正在朗读/等待朗读的 AI 消息下标(-1 = 没有)
        speaking: false,    // 是否正在发声
        blocked: false,     // H5 自动播放被浏览器拦过 → 按钮文案改成"点击朗读"
        warned: false       // 系统性故障是否已提示过(每次进入页面只提示一次)
      }
    };
  },
  computed: {
    // 音量条高度：H5 有真实音量时按音量起伏；小程序无音量回调，交给 CSS 动画兜底
    waveBars() {
      const shape = [0.45, 0.8, 1, 0.6, 0.95, 0.55, 0.75];
      const lv = this.voice.level || 0;
      return shape.map(f => Math.round(12 + f * lv * 52));
    },
    voiceTimerText() {
      const total = Math.floor(this.voiceElapsed / 1000);
      const p = n => String(n).padStart(2, '0');
      return `${p(Math.floor(total / 60))}:${p(total % 60)} / 01:00`;
    },
    voiceHintText() {
      if (this.voice.willCancel) return '松开手指，取消';
      // 与 utils/voice.js 的 AUTO_SEND_AFTER_ASR 联动，避免文案与行为不一致
      return AUTO_SEND_AFTER_ASR ? '松开发送 · 上滑取消' : '松手转文字 · 上滑取消';
    },
    isGenerating() {
      return ['connecting', 'thinking', 'streaming'].includes(this.requestState);
    },
    canSend() {
      return this.isGenerating || (this.inputValue.trim().length > 0 && this.inputValue.length <= this.inputMaxLength);
    },
    inputPlaceholder() {
      if (this.isGenerating) return '小召回答中，可继续输入…';
      return '说说场景和预算…';
    },
    replyStateText() {
      if (this.requestState === 'thinking') return '正在分析需求…';
      if (this.requestState === 'streaming') return '正在生成回答…';
      return '正在连接小召…';
    },
    hasQuickSelection() {
      return Boolean(this.quickBudget || this.quickTasks.length);
    },
    syncSummaryText() {
      const count = this.conversations.length;
      if (!this.loggedIn) return `仅保存在本机 · ${count} 段`;
      if (this.syncState === 'syncing') return `正在同步云端 · ${count} 段`;
      if (this.syncState === 'error') return `云端同步失败 · 本机 ${count} 段`;
      if (this.lastSyncAt) return `云端已同步 ${this.lastSyncAt} · ${count} 段`;
      return `登录后云端同步 · ${count} 段`;
    },
    filteredConversations() {
      const q = this.historyQuery.trim().toLowerCase();
      if (!q) return this.conversations;
      return this.conversations.filter((item) => {
        const messageText = Array.isArray(item.messages)
          ? item.messages.map(m => m && m.text ? String(m.text) : '').join('\n')
          : '';
        return [item.title || '', item.time || '', messageText]
          .join('\n')
          .toLowerCase()
          .includes(q);
      });
    }
  },
  // ⚠ 页面生命周期必须与 data/methods 平级,放进 methods 里框架不触发
  onReady() {
    this.measureChatViewport();
  },
  onShow() {
    this.activateConversationScope();
    this.measureChatViewport();
    this.loadConversations();
    this.loadTombstones();
    this.restoreDraft();
    trackAiEvent('ai_page_view', { logged_in: this.loggedIn });
    flushAiEvents();
    if (this.loggedIn) this.syncWithServer();
    const pendingQuery = uni.getStorageSync('aiPendingQuery');
    if (pendingQuery) {
      uni.removeStorageSync('aiPendingQuery');
      this.inputValue = String(pendingQuery);
    }

  },
  // ⚠ 本页是 tabBar 页(pages.json 里 custom:true)：切 tab **只触发 onHide,不触发 onUnload**。
  //   不在这里停播,切走之后朗读会一直在后台响 —— 最容易漏、用户又立刻能察觉的一条。
  onHide() {
    this.flushDraftSave();
    flushAiEvents();
    if (this.loggedIn) this.syncWithServer();
    this.stopTts();
    this.stopGeneration(true);
  },
  // 离开页面时收尾：停掉计时并结束会话，否则麦克风可能一直开着
  onUnload() {
    this.flushDraftSave();
    if (draftSaveTimer) { clearTimeout(draftSaveTimer); draftSaveTimer = null; }
    if (syncTimer) { clearTimeout(syncTimer); syncTimer = null; }
    this.stopVoiceTimer();
    if (voiceSession) { voiceSession.cancel(); voiceSession = null; }
    this.stopGeneration(true);
    // true = 连播放器一起销毁;本页是 tabBar 页,onUnload 很少走到,但真走到时要收干净
    this.stopTts(true);
  },
  methods: {
    activateConversationScope() {
      const token = uni.getStorageSync(TOKEN_KEY);
      if (!token) {
        this.loggedIn = false;
        this.accountScope = 'guest';
        return;
      }

      const customer = uni.getStorageSync(CUSTOMER_KEY) || {};
      const scope = customerStorageScope(customer);
      const previous = String(uni.getStorageSync('ai_last_account_scope') || '');

      // First login on this device may claim pre-login guest history. Later account
      // switches never copy one account's local state into another account.
      if (!previous) {
        ['summon_conversations', 'summon_conversation_tombstones', 'summon_ai_draft'].forEach((base) => {
          const guestValue = uni.getStorageSync(base);
          const scopedValue = uni.getStorageSync(`${base}:${scope}`);
          if (guestValue && !scopedValue) uni.setStorageSync(`${base}:${scope}`, guestValue);
        });
      }

      uni.setStorageSync('ai_last_account_scope', scope);
      this.accountScope = scope;
      this.loggedIn = true;
    },
    scopedStorageKey(base) {
      return this.accountScope === 'guest' ? base : `${base}:${this.accountScope}`;
    },
    // ===== 历史对话:本地持久化(uni storage) =====
    loadConversations() {
      try {
        const list = uni.getStorageSync(this.scopedStorageKey('summon_conversations'));
        const byId = new Map();
        (Array.isArray(list) ? list : []).forEach((item) => {
          if (!item) return;
          const normalized = this.normalizeConversation(item);
          const existing = byId.get(normalized.clientId);
          if (!existing || normalized.clientUpdatedAt > existing.clientUpdatedAt) {
            byId.set(normalized.clientId, normalized);
          }
        });
        this.conversations = Array.from(byId.values())
          .sort((a, b) => b.clientUpdatedAt - a.clientUpdatedAt)
          .slice(0, 30);
      } catch (e) {
        // 本地历史对话读取失败:退化为空列表(不阻断对话),但留 warn 不静默
        console.warn('[ai] 历史对话读取失败', e);
        this.conversations = [];
      }
    },
    saveConversations() {
      try {
        // 最多留 30 条,避免本地存储无限膨胀
        uni.setStorageSync(this.scopedStorageKey('summon_conversations'), this.conversations.slice(0, 30));
      } catch (e) { /* 存储失败不影响主流程 */ }
    },
    normalizeConversation(item) {
      const clientId = String(item.clientId || item.client_id || item.id || createConversationClientId());
      const clientUpdatedAt = Number(item.clientUpdatedAt || item.client_updated_at || Date.now());
      return {
        id: clientId,
        clientId,
        title: String(item.title || '新对话').slice(0, 191),
        time: item.time || formatConversationTime(clientUpdatedAt),
        clientUpdatedAt,
        messages: Array.isArray(item.messages) ? item.messages : [],
      };
    },
    loadTombstones() {
      try {
        const list = uni.getStorageSync(this.scopedStorageKey('summon_conversation_tombstones'));
        const cutoff = Date.now() - 90 * 24 * 60 * 60 * 1000;
        this.conversationTombstones = (Array.isArray(list) ? list : [])
          .map(item => ({
            clientId: String((item && (item.clientId || item.client_id)) || ''),
            clientUpdatedAt: Number(item && (item.clientUpdatedAt || item.client_updated_at)) || 0,
          }))
          .filter(item => item.clientId && item.clientUpdatedAt >= cutoff);
        this.saveTombstones();
      } catch (e) {
        this.conversationTombstones = [];
      }
    },
    saveTombstones() {
      try {
        uni.setStorageSync(this.scopedStorageKey('summon_conversation_tombstones'), this.conversationTombstones.slice(-60));
      } catch (e) { /* 存储失败不影响主流程 */ }
    },
    addTombstone(clientId, clientUpdatedAt) {
      const id = String(clientId || '');
      if (!id) return;
      const updatedAt = Number(clientUpdatedAt) || Date.now();
      this.conversationTombstones = this.conversationTombstones.filter(item => item.clientId !== id);
      this.conversationTombstones.push({ clientId: id, clientUpdatedAt: updatedAt });
      this.saveTombstones();
    },
    buildSyncPayload() {
      const records = new Map();
      const put = (record) => {
        if (!record || !record.client_id) return;
        const existing = records.get(record.client_id);
        if (!existing
          || record.client_updated_at > existing.client_updated_at
          || (record.client_updated_at === existing.client_updated_at && record.deleted && !existing.deleted)) {
          records.set(record.client_id, record);
        }
      };

      this.conversations.forEach((item) => {
        const clientId = item.clientId || item.id;
        put({
          client_id: clientId,
          title: item.title || '新对话',
          messages: Array.isArray(item.messages) ? item.messages : [],
          client_updated_at: Number(item.clientUpdatedAt) || Date.now(),
          deleted: false,
        });
      });

      this.conversationTombstones.forEach((item) => {
        put({
          client_id: item.clientId,
          title: '',
          messages: [],
          client_updated_at: Number(item.clientUpdatedAt) || Date.now(),
          deleted: true,
        });
      });

      return Array.from(records.values())
        .sort((a, b) => b.client_updated_at - a.client_updated_at)
        .slice(0, 50);
    },
    scheduleServerSync(delay = 1200) {
      if (!this.loggedIn) return;
      if (syncTimer) clearTimeout(syncTimer);
      syncTimer = setTimeout(() => {
        syncTimer = null;
        this.syncWithServer();
      }, delay);
    },
    async syncWithServer(manual = false) {
      if (!this.loggedIn) {
        if (manual) uni.showToast({ title: '登录后可跨设备同步', icon: 'none' })
        return
      }

      const seq = ++syncSeq
      this.syncState = 'syncing'
      this.syncAnnouncement = '正在同步云端历史对话'

      try {
        const res = await aiConversationApi.sync(this.buildSyncPayload())
        if (seq !== syncSeq) return
        const snapshot = res && res.data && Array.isArray(res.data.conversations)
          ? res.data.conversations
          : null
        if (!snapshot) throw new Error('同步响应格式异常')

        this.mergeServerSnapshot(snapshot)
        this.syncState = 'success'
        this.lastSyncAt = formatConversationTime(Date.now()).slice(-5)
        this.syncAnnouncement = `云端同步完成，共 ${this.conversations.length} 段对话`
        if (manual) uni.showToast({ title: '历史对话已同步', icon: 'none' })
      } catch (e) {
        if (seq !== syncSeq) return
        if (e && e.statusCode === 401) {
          this.activateConversationScope()
          this.draftRestored = false
          this.loadConversations()
          this.loadTombstones()
          this.restoreDraft()
        }
        this.syncState = 'error'
        this.syncAnnouncement = '云端同步失败，本机记录已保留'
        if (manual) uni.showToast({ title: '同步失败，本机记录已保留', icon: 'none' })
      }
    },
    mergeServerSnapshot(snapshot) {
      const records = new Map();
      const put = (record) => {
        if (!record || !record.clientId) return;
        const existing = records.get(record.clientId);
        if (!existing
          || record.clientUpdatedAt > existing.clientUpdatedAt
          || (record.clientUpdatedAt === existing.clientUpdatedAt && record.deleted && !existing.deleted)) {
          records.set(record.clientId, record);
        }
      };

      this.conversations.forEach((item) => {
        put({
          ...this.normalizeConversation(item),
          deleted: false,
        })
      });
      this.conversationTombstones.forEach(item => put({
        clientId: item.clientId,
        clientUpdatedAt: item.clientUpdatedAt,
        deleted: true,
      }));

      snapshot.forEach((item) => {
        const clientId = String(item.client_id || '')
        const clientUpdatedAt = Number(item.client_updated_at) || 0
        if (!clientId) return
        const deleted = Boolean(item.deleted_at)
        put(deleted ? { clientId, clientUpdatedAt, deleted: true } : {
          ...this.normalizeConversation({
            client_id: clientId,
            title: item.title,
            messages: item.messages,
            client_updated_at: clientUpdatedAt,
          }),
          deleted: false,
        })
      });

      const nextConversations = []
      const nextTombstones = []
      records.forEach((record) => {
        if (record.deleted) nextTombstones.push({ clientId: record.clientId, clientUpdatedAt: record.clientUpdatedAt })
        else nextConversations.push(record)
      })

      this.conversations = nextConversations
        .sort((a, b) => b.clientUpdatedAt - a.clientUpdatedAt)
        .slice(0, 30)
      this.conversationTombstones = nextTombstones
        .sort((a, b) => a.clientUpdatedAt - b.clientUpdatedAt)
        .slice(-60)
      this.saveConversations()
      this.saveTombstones()

      if (this.currentConvId) {
        const current = this.conversations.find(item => String(item.clientId || item.id) === String(this.currentConvId))
        if (current) this.messages = current.messages || []
        else this.resetCurrentConversation(false)
      }
    },
    manualSync() {
      this.syncWithServer(true)
    },

    // ===== 输入草稿:只在当前设备恢复，避免切 tab / 刷新后丢需求 =====
    restoreDraft() {
      if (this.draftRestored) return;
      this.draftRestored = true;
      try {
        const draftKey = this.scopedStorageKey('summon_ai_draft');
        const draft = uni.getStorageSync(draftKey);
        if (!draft) return;
        // 草稿保留 7 天，过期自动丢弃。
        if (draft.at && Date.now() - draft.at > 7 * 24 * 60 * 60 * 1000) {
          uni.removeStorageSync(draftKey);
          return;
        }
        if (!this.inputValue && typeof draft.inputValue === 'string') {
          this.inputValue = draft.inputValue;
        }
        const budgets = ['3万以下', '3-10万', '10-30万', '30万以上'];
        if (budgets.includes(draft.quickBudget)) this.quickBudget = draft.quickBudget;
        if (Array.isArray(draft.quickTasks)) this.quickTasks = draft.quickTasks.slice(0, 6);
        this.quickFilterExpanded = Boolean(this.quickBudget || this.quickTasks.length);
      } catch (e) {
        console.warn('[ai] 草稿恢复失败', e);
      }
    },
    onDraftInput(e) {
      const value = e && e.detail && typeof e.detail.value === 'string'
        ? e.detail.value
        : this.inputValue;
      this.queueDraftSave(value);
    },
    queueDraftSave(value) {
      if (draftSaveTimer) clearTimeout(draftSaveTimer);
      draftSaveTimer = setTimeout(() => {
        draftSaveTimer = null;
        this.persistDraft(value);
      }, 300);
    },
    persistDraft(value) {
      const inputValue = typeof value === 'string' ? value : this.inputValue;
      const quickBudget = this.quickBudget || '';
      const quickTasks = Array.isArray(this.quickTasks) ? this.quickTasks.slice() : [];
      const draftKey = this.scopedStorageKey('summon_ai_draft');
      try {
        if (!inputValue.trim() && !quickBudget && !quickTasks.length) {
          uni.removeStorageSync(draftKey);
          return;
        }
        uni.setStorageSync(draftKey, {
          inputValue,
          quickBudget,
          quickTasks,
          at: Date.now()
        });
      } catch (e) { /* 本地草稿失败不影响发送 */ }
    },
    flushDraftSave() {
      if (draftSaveTimer) {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
      }
      this.persistDraft(this.inputValue);
    },
    clearDraft() {
      if (draftSaveTimer) {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
      }
      try { uni.removeStorageSync(this.scopedStorageKey('summon_ai_draft')); } catch (e) { /* 静默 */ }
    },
    resetCurrentConversation(closeDrawer) {
      this.stopTts(true);
      this.currentConvId = null;
      this.messages = [];
      this.quickBudget = '';
      this.quickTasks = [];
      this.quickFilterExpanded = false;
      this.inputValue = '';
      this.lastUserPrompt = '';
      this.followLatest = true;
      this.newReplyAvailable = false;
      this.clearDraft();
      if (closeDrawer) this.drawerVisible = false;
    },
    // 开新对话:清空当前会话(历史仍保留在抽屉里)
    newConversation() {
      // ⚠ 必须停播:tts.activeIndex 是消息下标,整个 messages 被换掉后下标立刻失效
      this.stopTts(true);
      this.stopGeneration(true);
      this.resetCurrentConversation(true);
      this.historyQuery = '';
    },
    // 点历史项 → 载入那段对话(原来是空实现,点了没反应)
    loadHistory(item) {
      if (!item || item.id == null) return;
      // ⚠ 同上:整体替换 messages 会让朗读中的下标指向另一条消息
      this.stopTts(true);
      this.stopGeneration(true);
      this.currentConvId = item.clientId || item.id;
      this.messages = Array.isArray(item.messages) ? item.messages : [];
      this.followLatest = true;
      this.newReplyAvailable = false;
      this.historyQuery = '';
      this.drawerVisible = false;
      this.scrollToBottom(true);
    },
    deleteConversation(item) {
      if (!item || item.id == null) return;
      uni.showModal({
        title: '删除这段对话？',
        content: '删除后无法恢复，只会影响当前设备。',
        confirmText: '删除',
        confirmColor: '#D92D20',
        success: (res) => {
          if (!res.confirm) return;
          const wasCurrent = String(this.currentConvId) === String(item.id);
          if (wasCurrent) {
            this.stopTts(true);
            this.stopGeneration(true);
          }
          this.addTombstone(item.clientId || item.id, Date.now());
          this.conversations = this.conversations.filter(c => String(c.clientId || c.id) !== String(item.clientId || item.id));
          this.saveConversations();
          this.scheduleServerSync();
          if (wasCurrent) this.resetCurrentConversation(false);
        }
      });
    },
    clearConversations() {
      if (!this.conversations.length) return;
      uni.showModal({
        title: '清空全部历史？',
        content: '所有本地对话将被删除，且无法恢复。',
        confirmText: '清空',
        confirmColor: '#D92D20',
        success: (res) => {
          if (!res.confirm) return;
          this.stopTts(true);
          this.stopGeneration(true);
          const deletedAt = Date.now();
          this.conversations.forEach(item => this.addTombstone(item.clientId || item.id, deletedAt));
          this.conversations = [];
          this.saveConversations();
          this.scheduleServerSync();
          this.historyQuery = '';
          this.resetCurrentConversation(false);
        }
      });
    },
    // 把当前 messages 写回所属会话(没有就新建一条)
    persistCurrentConversation() {
      const first = this.messages.find(m => m.role === 'user');
      const title = first
        ? (first.text.length > 18 ? first.text.slice(0, 18) + '…' : first.text)
        : '新对话';
      const clientUpdatedAt = Date.now();
      const time = formatConversationTime(clientUpdatedAt);
      const idx = this.conversations.findIndex(c => String(c.clientId || c.id) === String(this.currentConvId));
      if (idx >= 0) {
        this.conversations[idx].messages = this.messages.slice();
        this.conversations[idx].time = time;
        this.conversations[idx].clientUpdatedAt = clientUpdatedAt;
        this.conversations[idx].title = title;
        // 最近说话的排最前
        const [c] = this.conversations.splice(idx, 1);
        this.conversations.unshift(c);
      } else {
        const id = this.currentConvId || createConversationClientId();
        this.currentConvId = id;
        this.conversations.unshift({
          id,
          clientId: id,
          clientUpdatedAt,
          title,
          time,
          messages: this.messages.slice(),
        });
      }
      this.saveConversations();
      this.scheduleServerSync();
    },
    toggleQuickFilter() {
      this.quickFilterExpanded = !this.quickFilterExpanded;
    },
    isTagSelected(tag) {
      if (['3万以下', '3-10万', '10-30万', '30万以上'].includes(tag)) {
        return this.quickBudget === tag;
      }
      return this.quickTasks.includes(tag);
    },
    onTagClick(tag) {
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' });
        return;
      }
      if (['3万以下', '3-10万', '10-30万', '30万以上'].includes(tag)) {
        this.quickBudget = this.quickBudget === tag ? '' : tag;
      } else {
        const index = this.quickTasks.indexOf(tag);
        if (index >= 0) this.quickTasks.splice(index, 1);
        else this.quickTasks.push(tag);
      }
      this.syncQuickTagsToInput();
    },
    syncQuickTagsToInput() {
      const allTags = ['3万以下', '3-10万', '10-30万', '30万以上', '分拣', '搬运', '焊接', '装配', '检测', '包装'];
      const manual = this.inputValue
        .split(/\s+/)
        .filter(t => t && !allTags.includes(t))
        .join(' ');
      const selected = [this.quickBudget, ...this.quickTasks].filter(Boolean);
      this.inputValue = [manual, ...selected].filter(Boolean).join(' ');
      this.queueDraftSave(this.inputValue);
    },
    startQuickMatch() {
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' });
        return;
      }
      if (!this.hasQuickSelection) {
        uni.showToast({ title: '请先选择预算或任务', icon: 'none' });
        return;
      }
      const allTags = ['3万以下', '3-10万', '10-30万', '30万以上', '分拣', '搬运', '焊接', '装配', '检测', '包装'];
      const manual = this.inputValue
        .split(/\s+/)
        .filter(t => t && !allTags.includes(t))
        .join(' ');
      const parts = [];
      if (manual) parts.push(manual);
      if (this.quickTasks.length) parts.push(`需要用于${this.quickTasks.join('、')}的机器人`);
      if (this.quickBudget) parts.push(`预算${this.quickBudget}`);
      this.inputValue = parts.join('，');
      this.onSendMsg();
    },
    onQuestionTap(q) {
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' });
        return;
      }
      // 推荐问题直接当一条消息发出去(原来是占位 toast,不发送)
      this.inputValue = q;
      this.onSendMsg();
    },
    onPrimaryAction() {
      if (this.isGenerating) this.stopGeneration();
      else this.onSendMsg();
    },
    onSendMsg() {
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' });
        return;
      }
      const val = this.inputValue.trim();
      if (!val) { uni.showToast({ title: '请描述你的需求', icon: 'none' }); return; }
      if (val.length > this.inputMaxLength) {
        uni.showToast({ title: `请控制在 ${this.inputMaxLength} 字以内`, icon: 'none' });
        return;
      }
      const requestId = createConversationClientId();
      const requestStartedAt = Date.now();
      const hadQuickSelection = this.hasQuickSelection;
      let firstResponseTracked = false;
      const trackFirstResponse = (source) => {
        if (firstResponseTracked) return;
        firstResponseTracked = true;
        trackAiEvent('ai_first_token', {
          request_id: requestId,
          latency_ms: Date.now() - requestStartedAt,
          source,
        });
      };
      trackAiEvent('ai_prompt_submit', {
        request_id: requestId,
        prompt_length: val.length,
        conversation_messages: this.messages.length,
        quick_filters: hadQuickSelection,
        logged_in: this.loggedIn,
      });

      // ⚠ 新提问 = 新的朗读会话,旧的立刻静音(否则上一段答案会盖着新回答继续念)
      this.stopTts();

      // 取已有对话作为上下文。
      // ⚠ 必须在 push 当前这句**之前**取 —— 否则当前这句也会进历史,后端再拼一次就成了重复提问。
      // ⚠ 必须带历史:后端实测每轮都会开一个新的 Coze 会话(而且它忽略请求里的
      //   conversation_id,所以"复用会话"那条路走不通)。不带历史的后果是机器人
      //   **完全记不住上文**:每轮都从开场白重来、反复问同一个问题 —— 用户看到的就是"重复回答"。
      //   后端会限长(最近 10 条 / 3000 字),这里传全量即可。
      const history = this.messages
        .filter(m => m.text)
        .map(m => ({ role: m.role, content: m.text }))

      // 先把用户这句推进对话流(历史记录在拿到回复后统一落盘)
      this.messages.push({ role: 'user', text: val })
      this.inputValue = ''
      this.quickBudget = ''
      this.quickTasks = []
      this.clearDraft()
      this.lastUserPrompt = val
      this.activeAiIndex = -1
      this.requestState = 'connecting'
      this.followLatest = true
      this.newReplyAvailable = false
      this.scrollToBottom(true)
      // 用户问题先落盘：即使中途退出或请求失败，也不会在历史里消失。
      this.persistCurrentConversation()

      const seq = ++activeSummonSeq
      activeSummonCancel = null
      const isCurrent = () => seq === activeSummonSeq

      // 正在生成的 AI 消息下标(-1 = 还没有内容,先不建气泡)
      let aiIndex = -1
      let lastScrollAt = 0
      let settled = false
      let fallbackStarted = false

      const ensureAi = () => {
        if (aiIndex < 0) {
          aiIndex = this.messages.length
          this.messages.push({
            role: 'ai', text: '', thinking: '', thinkingOpen: true, generating: true,
            retryPrompt: val, scene: null, machines: [], skills: [], accessories: [], followUps: []
          })
          this.activeAiIndex = aiIndex
        }
        return aiIndex
      }
      // 流式期间每 300ms 最多滚一次,否则每个增量都滚会很卡
      const scrollThrottled = () => {
        const now = Date.now()
        if (now - lastScrollAt < 300) return
        lastScrollAt = now
        this.scrollToBottom()
      }

      const finishError = (message) => {
        if (!isCurrent()) return
        settled = true
        activeSummonCancel = null
        this.stopTts()
        const i = ensureAi()
        const m = this.messages[i]
        m.generating = false
        m.interrupted = false
        m.error = message || '回答生成中断，请重试'
        m.retryPrompt = val
        m.customCta = false
        this.requestState = 'error'
        this.activeAiIndex = -1
        this.persistCurrentConversation()
        this.scrollToBottom()
        trackAiEvent('ai_reply_error', {
          request_id: requestId,
          elapsed_ms: Date.now() - requestStartedAt,
          had_partial_text: Boolean(m.text),
          stage: fallbackStarted ? 'fallback' : 'stream',
        });
      }

      // 回退:流式不可用(部分平台/老版本不完全支持)时,退回一次性接口 + 打字机
      const fallback = () => {
        if (!isCurrent() || fallbackStarted) return
        fallbackStarted = true
        settled = true
        activeSummonCancel = null
        this.requestState = 'thinking'
        // 切到另一条链路,旧朗读作废(splice 掉气泡会让朗读中的下标失效)
        this.stopTts();
        if (aiIndex >= 0) { this.messages.splice(aiIndex, 1); aiIndex = -1; this.activeAiIndex = -1 }
        summonApi.summon(val, history)
          .then((res) => {
            if (!isCurrent()) return
            trackFirstResponse('fallback')
            const r = res || {}
            let text = r.answer || ''
            if (!text) {
              if (r.scene) text = '为你找到场景：' + r.scene.name
              else if ((r.machines || []).length) text = '为你筛选到以下机型：'
              else text = '暂时没有匹配的方案，可试试「方案定制」由专家为你定制'
            }
            this.requestState = 'streaming'
            this.revealAiMessage({
              text, scene: r.scene || null,
              machines: r.machines || [], skills: r.skills || [], accessories: r.accessories || [],
              retryPrompt: val
            }, {
              isCancelled: () => !isCurrent(),
              onComplete: () => {
                if (!isCurrent()) return
                this.requestState = 'idle'
                this.activeAiIndex = -1
                trackAiEvent('ai_reply_done', {
                  request_id: requestId,
                  elapsed_ms: Date.now() - requestStartedAt,
                  recommendations: this.recommendationCount(r),
                  follow_ups: (r.follow_ups || []).length,
                  source: 'fallback',
                })
              }
            })
          })
          .catch((err) => {
            if (!isCurrent()) return
            finishError('抱歉，这次没连上小召。' + this.friendlySummonError(err))
          })
      }

      summonStream(val, {
        onRequestReady: (cancel) => {
          if (!isCurrent()) return
          activeSummonCancel = cancel
        },
        onThinking: (t) => {
          if (!isCurrent() || settled) return
          trackFirstResponse('stream')
          this.requestState = 'thinking'
          const i = ensureAi()
          this.messages[i].thinking += t
          scrollThrottled()
        },
        onDelta: (t) => {
          if (!isCurrent() || settled) return
          trackFirstResponse('stream')
          this.requestState = 'streaming'
          const i = ensureAi()
          this.messages[i].thinkingOpen = false   // 正文开始了,深度思考自动收起
          this.messages[i].text += t
          scrollThrottled()

          // 朗读:把增量喂给朗读会话,它自己按句边界切分后逐句合成播放。
          // ⚠ 喂的是**原始增量**(含 markdown)—— 清洗在 utils/tts.js 的 cleanForSpeech 里做,
          //   因为 done.answer 才是后端清洗过的,delta 是 Coze 原文(已核实)。
          this.ensureTts(i)
          if (ttsSession) ttsSession.feed(t)
        },
        onDone: (d) => {
          if (!isCurrent() || settled) return
          settled = true
          activeSummonCancel = null
          const i = ensureAi()
          const m = this.messages[i]
          // 以服务端给的完整正文为准(流里尾部预留了 12 字符没发)
          m.text = d.answer || m.text
          m.scene = d.scene || null
          m.machines = d.machines || []
          m.skills = d.skills || []
          m.accessories = d.accessories || []
          // 推荐追问:Coze 在正文之后另发的 type=follow_up 消息,渲染成可点的问题
          m.followUps = d.follow_ups || []
          m.generating = false
          m.interrupted = false
          m.error = ''
          m.retryPrompt = val
          m.customCta = !this.hasRecommendations(d)
          this.requestState = 'idle'
          this.activeAiIndex = -1
          this.persistCurrentConversation()
          this.scrollToBottom()
          trackAiEvent('ai_reply_done', {
            request_id: requestId,
            elapsed_ms: Date.now() - requestStartedAt,
            recommendations: this.recommendationCount(d),
            follow_ups: (d.follow_ups || []).length,
            source: 'stream',
          })

          // 朗读:把权威正文交给朗读会话收尾。
          // ⚠ 必须传 d.answer —— 后端 relayCoze 预留了 12 字符不发(还遇【推荐机型】就停发),
          //   流式累计文本是 done.answer 的真前缀且缺尾巴,不补的话最后一句会被吃掉半截。
          // 这里再 ensureTts 一次,是为了覆盖"只收到 thinking、没收到 delta"的边角情况。
          if (d && d.answer) {
            this.ensureTts(i)
            if (ttsSession) ttsSession.end(d.answer)
          }
        },
        onError: (msg) => {
          if (!isCurrent() || settled) return
          const i = aiIndex
          // 流断了朗读也就没有下文了(继续念半截反而奇怪)
          this.stopTts()
          // 一个字都没收到 → 说明流式根本没跑起来,回退到一次性接口
          if (i < 0 || !this.messages[i] || !this.messages[i].text) { fallback(); return }
          finishError('回答生成中断，请重试')
        },
        onClose: () => {
          if (!isCurrent() || settled) return
          const i = aiIndex
          if (i < 0 || !this.messages[i] || !this.messages[i].text) { fallback(); return }
          finishError('连接已中断，当前回答不完整')
        }
      }, history)
    },
    hasRecommendations(data) {
      if (!data) return false
      return Boolean(
        data.scene ||
        (data.machines && data.machines.length) ||
        (data.skills && data.skills.length) ||
        (data.accessories && data.accessories.length)
      )
    },
    recommendationCount(data) {
      if (!data) return 0
      return (data.scene ? 1 : 0)
        + ((data.machines || []).length)
        + ((data.skills || []).length)
        + ((data.accessories || []).length)
    },
    friendlySummonError(err) {
      const raw = String((err && (err.message || err.errMsg)) || '').toLowerCase();
      if (/timeout|超时/.test(raw)) return '请求超时，请稍后重试';
      if (/401|403|unauthorized|登录/.test(raw)) return '登录状态已失效，请重新进入';
      if (/network|failed to fetch|err_network|网络|连接/.test(raw)) return '网络连接失败，请检查网络后重试';
      return '服务暂时不可用，请稍后重试';
    },
    stopGeneration(silent) {
      if (!this.isGenerating) return
      activeSummonSeq += 1
      if (activeSummonCancel) {
        try { activeSummonCancel() } catch (e) { /* 静默 */ }
      }
      activeSummonCancel = null
      this.stopTts()
      const i = this.activeAiIndex
      if (i >= 0 && this.messages[i] && this.messages[i].role === 'ai') {
        this.messages[i].generating = false
        this.messages[i].interrupted = true
        this.messages[i].error = ''
        this.messages[i].retryPrompt = this.lastUserPrompt
      } else if (this.lastUserPrompt) {
        this.messages.push({
          role: 'ai', text: '已停止生成。', interrupted: true,
          retryPrompt: this.lastUserPrompt, scene: null,
          machines: [], skills: [], accessories: [], followUps: []
        })
      }
      this.requestState = 'idle'
      this.activeAiIndex = -1
      this.persistCurrentConversation()
      trackAiEvent('ai_stop', {
        request_state: 'stopped',
        partial_text_length: i >= 0 && this.messages[i] ? String(this.messages[i].text || '').length : 0,
      })
      if (!silent) uni.showToast({ title: '已停止生成', icon: 'none' })
    },
    retryMessage(prompt) {
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' })
        return
      }
      const q = String(prompt || this.lastUserPrompt || '').trim()
      if (!q) {
        uni.showToast({ title: '没有可重试的问题', icon: 'none' })
        return
      }
      trackAiEvent('ai_retry', { prompt_length: q.length })
      this.inputValue = q
      this.onSendMsg()
    },
    openCustomPlan(prompt) {
      const demand = String(prompt || this.lastUserPrompt || this.inputValue || '').trim()
      trackAiEvent('ai_custom_plan_click', { demand_length: demand.length, logged_in: this.loggedIn })
      try {
        uni.setStorageSync('aiSummonCustomDraft', { demand, source: 'ai', at: Date.now() })
      } catch (e) { /* 存储失败不阻断跳转 */ }
      uni.navigateTo({ url: '/pages/robot/fangan?mode=custom&source=ai&title=AI%E5%8F%AC%E5%94%A4%E5%AE%9A%E5%88%B6%E9%9C%80%E6%B1%82' })
    },

    // 以「打字机」方式吐出一条 AI 消息(模拟流式输出)。
    //
    // 现在后端是等 Coze 出完整答案才返回(8~20 秒),整段"啪"一下出现很生硬;
    // 这里让正文逐字显现,做出"边想边说"的过程感。卡片等正文吐完再一起出现。
    //
    // ⚠ 这个方法就是**渲染层**:后续换真流式时,只要把"逐字填"换成"按到达的片段填",
    //   上层逻辑不用重写。
    revealAiMessage(msg, opts) {
      opts = opts || {}
      // speak:false 用于"不该念出来"的场景(如"没连上小召"这种报错提示)
      const speak = !opts || opts.speak !== false
      const full = String(msg.text || '')
      const index = this.messages.length
      const isCancelled = () => Boolean(opts.isCancelled && opts.isCancelled())
      // 先插入一条空气泡,再逐字填
      this.messages.push({
        role: 'ai', text: '', generating: true, interrupted: false, error: '',
        retryPrompt: msg.retryPrompt || this.lastUserPrompt,
        scene: null, machines: [], skills: [], accessories: [], followUps: []
      })
      this.activeAiIndex = index

      // 打字机路径同样要朗读,且要"边打字边读" —— 只喂新吐出来的那一段
      if (speak && full) this.ensureTts(index)
      let fed = 0

      const finish = () => {
        if (isCancelled()) {
          this.stopTts()
          return
        }
        const m = this.messages[index]
        if (!m || m.role !== 'ai') {
          // 期间被清空/切了会话,朗读一起作废
          this.stopTts()
          return
        }
        m.text = full
        m.scene = msg.scene || null
        m.machines = msg.machines || []
        m.skills = msg.skills || []
        m.accessories = msg.accessories || []
        m.generating = false
        m.interrupted = false
        m.error = ''
        m.customCta = !this.hasRecommendations(msg)
        // 用完整正文收尾,把打字机没来得及喂的最后一片补上
        if (speak && ttsSession) ttsSession.end(full)
        this.persistCurrentConversation()
        this.scrollToBottom()
        if (opts.onComplete) opts.onComplete()
      }

      if (!full) { finish(); return }

      // 约 60 步吐完,整体 ~1.8 秒——与文本长短无关,长答案也不会让人等太久
      const chunk = Math.max(1, Math.ceil(full.length / 60))
      let shown = 0
      const timer = setInterval(() => {
        if (isCancelled()) {
          clearInterval(timer)
          this.stopTts()
          return
        }
        shown += chunk
        if (shown >= full.length) {
          clearInterval(timer)
          finish()
          return
        }
        const m = this.messages[index]
        if (!m || m.role !== 'ai') { clearInterval(timer); this.stopTts(); return }
        const visible = full.slice(0, shown)
        m.text = visible
        // ★ 只喂"新吐出来的那一段"。整段重喂会把已经念过的再念一遍
        if (speak && ttsSession && visible.length > fed) {
          ttsSession.feed(visible.slice(fed))
          fed = visible.length
        }
      }, 30)
    },
    // ===== 朗读(TTS)=====

    /**
     * 组装朗读会话的回调。ensureTts 与 onSpeakTap 共用,避免两份。
     *
     * 临时性失败(单句网络抖动)完全静默 —— 由 tts.js 自己跳过继续。
     * 但**系统性故障**(资源包用尽 / 鉴权失败 / 音色 ID 错)必须让用户知道:
     * 不提示的话,用户遇到的就是"点了没声音"这种无从诊断的黑盒。
     */
    makeTtsHandlers(index) {
      return {
        onStart: () => { this.tts.speaking = true },
        onEnd: () => {
          this.tts.speaking = false
          if (this.tts.activeIndex === index) this.tts.activeIndex = -1
        },
        // H5 自动播放被浏览器拦下:把按钮文案改成"点击朗读",让用户点一下即可
        onBlocked: () => { this.tts.blocked = true; this.tts.speaking = false },
        onError: (code) => {
          this.tts.speaking = false
          // 每次进入页面只提示一次,避免每条消息都弹。
          // ⚠ 三种都要提示,否则会出现"点了没反应且毫无解释"——
          //   这次踩过的正是这类坑。NO_SUPPORT 只会由**手动点喇叭**触发
          //   (自动朗读在 no-op 会话里是静默的),所以不会凭空弹出来。
          const worthTelling = code === TTS_ERR.NOT_CONFIGURED
            || code === TTS_ERR.UNAVAILABLE
            || code === TTS_ERR.NO_SUPPORT

          if (worthTelling && !this.tts.warned) {
            this.tts.warned = true
            uni.showToast({ title: TTS_ERR_TEXT[code] || '朗读功能暂不可用', icon: 'none' })
          }
        }
      }
    },

    /** 为第 index 条消息开一个朗读会话(已经在为同一条朗读时直接复用) */
    ensureTts(index) {
      if (ttsSession && this.tts.activeIndex === index) return
      this.stopTts()
      this.tts.activeIndex = index
      this.tts.blocked = false
      ttsSession = createTtsSession(this.makeTtsHandlers(index))
    },

    /** 停止朗读。destroy=true 时连播放器一起销毁(切会话/离开页面用) */
    stopTts(destroy) {
      if (ttsSession) {
        try { destroy ? ttsSession.destroy() : ttsSession.stop() } catch (e) { /* 静默 */ }
        ttsSession = null
      }
      this.tts.activeIndex = -1
      this.tts.speaking = false
    },

    /** 喇叭按钮文案(模板里直接当函数用) */
    speakLabel(i) {
      if (this.tts.activeIndex === i) {
        return this.tts.speaking ? '🔊 朗读中 · 点击停止' : '🔊 准备朗读…'
      }
      return this.tts.blocked ? '🔈 点击朗读' : '🔊 重播'
    },

    /** 点喇叭:正在念这条就停;否则整段重播 */
    onSpeakTap(i) {
      const m = this.messages[i]
      if (!m || !m.text) return

      if (this.tts.activeIndex === i) { this.stopTts(); return }

      this.stopTts()
      this.tts.activeIndex = i
      this.tts.blocked = false
      ttsSession = createTtsSession(this.makeTtsHandlers(i))
      try { ttsSession.speak(m.text) } catch (e) { this.stopTts() }
    },

    onCopyAnswer(i) {
      const m = this.messages[i]
      if (!m || !m.text) return
      trackAiEvent('ai_copy_answer', { answer_length: m.text.length })
      uni.setClipboardData({
        data: m.text,
        success: () => uni.showToast({ title: '回答已复制', icon: 'none' }),
        fail: () => uni.showToast({ title: '复制失败，请长按选择文字', icon: 'none' })
      })
    },

    onChatScroll(e) {
      const d = (e && e.detail) || {};
      this.chatScrollTop = Number(d.scrollTop || 0);
      this.chatScrollHeight = Number(d.scrollHeight || 0);
      this.chatViewportHeight = Number(d.clientHeight || this.chatViewportHeight || 0);
      if (this.chatScrollHeight > 0 && this.chatViewportHeight > 0) {
        const distance = this.chatScrollHeight - this.chatScrollTop - this.chatViewportHeight;
        this.followLatest = distance < 140;
        if (this.followLatest) this.newReplyAvailable = false;
      }
    },
    measureChatViewport() {
      this.$nextTick(() => {
        const query = uni.createSelectorQuery().in(this);
        query.select('.chat-area').boundingClientRect((rect) => {
          if (rect && rect.height) this.chatViewportHeight = Number(rect.height);
        }).exec();
      });
    },

    // 滚到对话底部。用户上滑阅读时不再强制拽回，只提示有新内容。
    scrollToBottom(force) {
      if (!force && !this.followLatest) {
        this.newReplyAvailable = true;
        return;
      }
      this.followLatest = true;
      this.newReplyAvailable = false;
      this.$nextTick(() => {
        // 先清空再置回,保证连续两次提问也能触发滚动
        this.scrollAnchor = ''
        this.$nextTick(() => { this.scrollAnchor = 'chat-tail' })
      })
    },
    goSceneDetail(scene) {
      trackAiEvent('ai_recommendation_click', { recommendation_type: 'scene', target_id: String(scene.id || '') })
      uni.navigateTo({ url: `/pages/robot/fangan?sceneId=${scene.id}` })
    },
    createSceneRequest(index) {
      const question=this.messages.slice(0,index).reverse().find(message=>message.role==='user')
      if(!question?.text)return
      openSceneRequest(sceneDraftPayload({source:'ai',text:question.text,category:'enterprise',contact:{name:'',phone:''}}))
    },
    // 点推荐卡片 → 打开机器人库页的商品详情
    //
    // 沿用项目既有做法(见 robot/index.vue 的 onShow 里读 accessoryDetail/skillDetail):
    // 先把要打开的商品写进 storage,再跳转;目标页 onShow 读出来后自动弹详情。
    // 只传 id、不传 type —— 由机器人页遍历「租用/购买/技能/配件」四个目录按 id 匹配,
    // 避免"同一个商品不知道属于哪个分类"的问题。
    goItemDetail(m, type = 'unknown') {
      if (!m || !m.id) return
      trackAiEvent('ai_recommendation_click', { recommendation_type: type, target_id: String(m.id) })
      try {
        uni.setStorageSync('aiOpenProduct', { id: m.id })
      } catch (e) {
        uni.showToast({ title: '打开详情失败', icon: 'none' })
        return
      }
      // ⚠ /pages/robot/index 是 tabBar 页,必须用 switchTab ——
      // 用 navigateTo 会报 "can not navigateTo a tabbar page"。
      // switchTab 不支持 URL 传参,所以商品数据只能走 storage。
      uni.switchTab({ url: '/pages/robot/index' })
    },
    // ===== 语音输入：按住说话 → 转文字 → 填进输入框 =====

    onVoiceStart(e) {
      // AI 正在流式输出时不让说话，避免"AI 还在答、用户又发一条"把对话流搞乱
      if (this.isGenerating) {
        uni.showToast({ title: '小召正在回答，请稍候', icon: 'none' });
        return;
      }
      if (!isVoiceSupported()) {
        uni.showToast({ title: ERR_TEXT[ERR.NO_SUPPORT], icon: 'none' });
        return;
      }

      // 记下起点 Y，onVoiceMove 用它判断"上滑取消"
      this.voiceTouchStartY = (e.touches && e.touches[0]) ? e.touches[0].clientY : 0;

      voiceSession = createVoiceSession({
        onStart: this.onVoiceSessionStart,
        onPartial: (text) => { this.voice.partial = text; },
        onLevel: (v) => { this.voice.level = v; },
        onResult: this.onVoiceResult,
        onError: this.onVoiceError,
        onEnd: this.onVoiceSessionEnd
      });

      voiceSession.start();
    },

    onVoiceMove(e) {
      if (!this.voice.visible) return;
      const y = (e.touches && e.touches[0]) ? e.touches[0].clientY : 0;
      // 上滑超过 120rpx 进入取消态
      this.voice.willCancel = (this.voiceTouchStartY - y) > uni.upx2px(120);
    },

    onVoiceEnd() {
      if (!voiceSession) return;
      if (this.voice.willCancel) voiceSession.cancel();
      else voiceSession.stop();
    },

    // ⚠ 来电 / 切后台 / 手势被打断都会走这里。不处理的话会话会悬挂、麦克风一直开着
    onVoiceCancel() {
      if (voiceSession) voiceSession.cancel();
    },

    onVoiceSessionStart() {
      this.voice.visible = true;
      this.voice.partial = '';
      this.voice.willCancel = false;
      this.voice.level = 0;
      this.voice.realtime = isRealtime();
      this.voiceElapsed = 0;
      this.startVoiceTimer();
    },

    // 浮层的关闭统一走这里，不要分散在 onResult/onError 里
    onVoiceSessionEnd() {
      this.stopVoiceTimer();
      this.voice.visible = false;
      this.voice.partial = '';
      this.voice.level = 0;
      this.voice.willCancel = false;
      voiceSession = null;
    },

    onVoiceResult(text) {
      const t = (text || '').trim();
      if (!t) return;
      trackAiEvent('ai_voice_result', { text_length: t.length, realtime: this.voice.realtime });

      // 已有内容时追加而不是覆盖，免得把用户打的字冲掉
      this.inputValue = this.inputValue ? (this.inputValue + ' ' + t) : t;
      this.queueDraftSave(this.inputValue);

      // 默认不自动发送：语音必有错字，而一次召唤要等 8~20 秒，代价太高
      if (AUTO_SEND_AFTER_ASR) this.onSendMsg();
    },

    onVoiceError(code, message) {
      if (code === ERR.PERMISSION_DENIED) {
        this.guideRecordPermission();
        return;
      }

      // 短按算误触，第一次不报错，改成教用户怎么用
      if (code === ERR.TOO_SHORT && !this.voiceHinted) {
        this.voiceHinted = true;
        uni.showToast({ title: '按住说话，松开结束', icon: 'none' });
        return;
      }

      uni.showToast({ title: message || ERR_TEXT[ERR.UNKNOWN], icon: 'none' });
    },

    // 权限被拒时的引导。两端能力完全不同，必须分开说：
    //   · 小程序 —— 能用 uni.openSetting 直接跳设置页
    //   · H5     —— **没有这个能力**。权限只能由用户自己在浏览器里改，
    //               所以要给清楚"点哪里"，只说"去设置"会让人完全摸不着头脑
    guideRecordPermission() {
      // #ifdef MP-WEIXIN
      uni.showModal({
        title: '需要麦克风权限',
        content: '开启后才能使用语音输入，现在去设置里打开？',
        confirmText: '去设置',
        success: (r) => { if (r.confirm) uni.openSetting({}); }
      });
      return;
      // #endif

      // #ifndef MP-WEIXIN
      uni.showModal({
        title: '需要麦克风权限',
        content: '浏览器拦住了麦克风。点地址栏左边的锁形图标（或「网站设置」），把「麦克风」改成「允许」，然后刷新页面。',
        showCancel: false,
        confirmText: '知道了'
      });
      // #endif
    },

    startVoiceTimer() {
      this.stopVoiceTimer();
      voiceTimerId = setInterval(() => { this.voiceElapsed += 1000; }, 1000);
    },

    stopVoiceTimer() {
      if (voiceTimerId) {
        clearInterval(voiceTimerId);
        voiceTimerId = null;
      }
    },
    toggleDrawer() {
      const opening = !this.drawerVisible;
      this.drawerVisible = opening;
      this.historySearchFocused = false;
      if (opening) {
        trackAiEvent('ai_history_open', { conversation_count: this.conversations.length, logged_in: this.loggedIn });
        this.$nextTick(() => { this.historySearchFocused = true; });
        if (this.loggedIn) this.syncWithServer();
      }
    },
    closeDrawer() {
      this.drawerVisible = false;
      this.historySearchFocused = false;
    },
    onHistorySearchConfirm() {
      trackAiEvent('ai_history_search', { query_length: this.historyQuery.trim().length });
    }
  }
};
</script>

<style scoped lang="scss">
/* ===== 背景图片 + 禁止页面滚动 ===== */
page {
  background: linear-gradient(180deg, #4291F6 0%, #B3D5FF 100%);
  min-height: 100vh;
  height: 100%;
  overflow: hidden;
}

.page-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: transparent;
  position: relative;
  z-index: 0;
  overflow: hidden;
}
.page-bg {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: -1;
}

/* ===== 顶部导航 ===== */
.nav-area {
  flex-shrink: 0;
  padding: calc(80rpx + env(safe-area-inset-top)) 32rpx 10rpx 32rpx;
  padding: calc(80rpx + constant(safe-area-inset-top)) 32rpx 10rpx 32rpx;
  background: transparent;
  position: relative;
  z-index: 20;
}
.nav-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.nav-left {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4rpx;
  transform: translate(60rpx, 90rpx);
}
.brand {
  font-size: 56rpx;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 2rpx;
  text-shadow: 0 4rpx 12rpx rgba(0, 0, 0, 0.20);
  line-height: 1.2;
}
.subtitle {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.95);
  background: rgba(0, 0, 0, 0.2);
  padding: 4rpx 16rpx;
  border-radius: 30rpx;
  backdrop-filter: blur(4rpx);
  margin-left: 0;
  align-self: flex-start;
}
.nav-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  /* ＋新对话 删掉后这里只剩 ☰,但要留住原来的高度:
     nav-content 一旦变矮,align-items:center 会把「AI小召」往上带,
     nav-area 整体变矮又会把下面的对话区一起提上去。
     131rpx ≈ ☰(约 77rpx) + gap(12rpx) + 原按钮(约 42rpx) */
  min-height: 131rpx;
  /* 下移到胶囊下沿之下。要调幅度改这一个值 */
  transform: translateY(96rpx);
}
.menu-icon {
  font-size: 44rpx;
  /* 碳黑灰字 */
  color: #333333;
  opacity: 0.9;
  transform: scaleX(1.2);
  text-shadow: 0 2rpx 8rpx rgba(0,0,0,0.15);
  padding: 12rpx 16rpx;
  border-radius: 12rpx;
  background: rgba(255, 255, 255, 0.10);
  backdrop-filter: blur(8rpx);
  transition: all 0.2s;
}
.menu-icon:active {
  background: rgba(255, 255, 255, 0.25);
  transform: scaleX(1.2) scale(0.92);
}

/* ===== 内容区域 ===== */
.chat-area {
  flex: 1;
  padding: calc(40rpx + 2cm) 32rpx 20rpx 32rpx;
  position: relative;
  z-index: 10;
  /* scroll-view 在 flex 列里必须 height:0 + flex:1 才能正确滚动 */
  height: 0;
  box-sizing: border-box;
  /* 隐藏滚动条:小程序靠标签上的 show-scrollbar="false",H5 只能靠 CSS */
  scrollbar-width: none;
  -ms-overflow-style: none;
}
/* H5 的浏览器默认滚动条又粗又显眼,这条才能真正去掉 */
.chat-area::-webkit-scrollbar { width: 0 !important; height: 0 !important; display: none; }

/* ===== 对话流(一问一答) ===== */
.msg { display: flex; flex-direction: column; margin-bottom: 24rpx; }
.msg.user { align-items: flex-end; }
.msg.ai { align-items: flex-start; }
.bubble { max-width: 84%; border-radius: 20rpx; padding: 20rpx 24rpx; }
.bubble-text { font-size: 27rpx; line-height: 1.7; word-break: break-word; }
.bubble.user { background: #165DFF; }
.bubble.user .bubble-text { color: #FFFFFF; }
.bubble.ai { background: rgba(255, 255, 255, 0.96); border: 1rpx solid #E2E8F0; }
.bubble.ai .bubble-text { color: #1F2937; }
.bubble-thinking { opacity: 0.75; }
/* 回答工具:贴在 AI 气泡下方、推荐卡片之前 */
.answer-actions { display: flex; align-items: center; gap: 10rpx; margin-top: 8rpx; }
.speak, .copy-answer { display: inline-flex; align-items: center; justify-content: center; padding: 8rpx 20rpx; background: rgba(255, 255, 255, 0.72); border: 1rpx solid #D6E4FF; border-radius: 30rpx; }
.speak:active { transform: scale(0.94); }
.copy-answer:active { transform: scale(0.94); }
.speak-text { font-size: 24rpx; color: #165DFF; }

/* H5 键盘焦点必须有可见反馈；小程序端会自动忽略这些规则。 */
.menu-icon:focus-visible,
.q-btn:focus-visible,
.quick-filter-toggle:focus-visible,
.tag:focus-visible,
.quick-start:focus-visible,
.think-head:focus-visible,
.speak:focus-visible,
.copy-answer:focus-visible,
.new-reply-tip:focus-visible,
.send-icon:focus-visible,
.drawer-new:focus-visible,
.drawer-close:focus-visible,
.drawer-sync:focus-visible,
.drawer-clear:focus-visible,
.drawer-search-clear:focus-visible,
.drawer-item:focus-visible,
.drawer-item-delete:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 3rpx;
}

.sr-only {
  position: absolute !important;
  width: 1px !important;
  height: 1px !important;
  padding: 0 !important;
  margin: -1px !important;
  overflow: hidden !important;
  clip: rect(0, 0, 0, 0) !important;
  white-space: nowrap !important;
  border: 0 !important;
}

/* 请求过程 / 中断 / 错误恢复 */
.reply-state {
  width: 84%;
  margin-top: 12rpx;
  padding: 14rpx 18rpx;
  display: flex;
  align-items: center;
  gap: 12rpx;
  background: rgba(255, 255, 255, 0.82);
  border: 1rpx solid #D6E4FF;
  border-radius: 16rpx;
  box-sizing: border-box;
}
.reply-state-dot {
  width: 12rpx;
  height: 12rpx;
  border-radius: 50%;
  background: #165DFF;
  box-shadow: 0 0 0 6rpx rgba(22, 93, 255, 0.12);
}
.reply-state-text { flex: 1; font-size: 24rpx; color: #64748B; line-height: 1.5; }
.reply-state-action { flex-shrink: 0; font-size: 24rpx; color: #165DFF; padding: 6rpx 4rpx; }
.reply-state.stopped { border-color: #E2E8F0; }
.reply-state.error { align-items: flex-start; flex-direction: column; border-color: #FECACA; background: rgba(255, 247, 247, 0.95); }
.reply-error-text { font-size: 24rpx; line-height: 1.6; color: #B42318; }
.reply-error-actions { display: flex; gap: 14rpx; margin-top: 6rpx; }
.reply-error-btn { font-size: 24rpx; color: #475569; padding: 10rpx 20rpx; border: 1rpx solid #CBD5E1; border-radius: 30rpx; background: #FFFFFF; }
.reply-error-btn.primary { color: #FFFFFF; border-color: #165DFF; background: #165DFF; }

/* 推荐追问:贴在推荐卡片之后的可点问题,点一下即作为下一句发送 */
.followups { width: 100%; margin-top: 16rpx; }
.followup { padding: 18rpx 24rpx; margin-bottom: 12rpx; background: rgba(255, 255, 255, 0.96); border: 1rpx solid #D6E4FF; border-radius: 16rpx; }
.followup:active { transform: scale(0.98); }
.followup-text { font-size: 26rpx; color: #165DFF; line-height: 1.5; }
.picks { width: 100%; margin-top: 12rpx; }
.chat-tail { height: 40rpx; }

/* ===== 深度思考(可折叠,内容实时流入) ===== */
.think { width: 100%; max-width: 84%; background: rgba(240, 246, 255, 0.92); border: 1rpx solid #D6E4FF; border-radius: 16rpx; padding: 14rpx 18rpx; margin-bottom: 10rpx; }
.think-head { display: flex; align-items: center; justify-content: space-between; }
.think-title { font-size: 24rpx; color: #165DFF; font-weight: 600; }
.think-toggle { font-size: 22rpx; color: #94A3B8; }
.think-body { margin-top: 10rpx; }
.think-text { font-size: 23rpx; line-height: 1.65; color: #64748B; }

/* ===== 融合后的主卡片（半透明） ===== */
.main-card {
  background: rgba(255, 255, 255, 0.45);
  backdrop-filter: blur(20rpx);
  border-radius: 40rpx;
  padding: 36rpx 32rpx 32rpx 32rpx;
  box-shadow: 0 16rpx 48rpx rgba(0, 0, 0, 0.12);
  border: 1rpx solid rgba(255, 255, 255, 0.4);
  position: relative;
  overflow: visible;
  margin-left: 30rpx;
}

.card-section {
  position: relative;
}
.card-header {
  margin-bottom: 22rpx;
}
.card-heading {
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}
.card-title {
  font-size: 34rpx;
  font-weight: 600;
  color: #1a2a3a;
}
.card-subtitle {
  font-size: 24rpx;
  line-height: 1.5;
  color: #607080;
}
.icon-sparkle {
  margin-right: 10rpx;
  font-size: 30rpx;
}
.card-date {
  font-size: 24rpx;
  color: #4a5a6a;
  background: rgba(255, 255, 255, 0.5);
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
}
.question-btns {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}
.question-label {
  margin-bottom: 2rpx;
  font-size: 23rpx;
  color: #6b7a88;
}
.q-btn {
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(8rpx);
  border: 1rpx solid rgba(255, 255, 255, 0.8);
  border-radius: 60rpx;
  padding: 18rpx 32rpx;
  font-size: 28rpx;
  color: #1a2a3a;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 16rpx;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
  position: relative;
  overflow: hidden;
}
.q-arrow {
  margin-left: auto;
  flex-shrink: 0;
  color: #7b8fa5;
  font-size: 34rpx;
}
.q-btn::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  width: 6rpx;
  height: 100%;
  background: linear-gradient(180deg, #4291F6, #6EB5FF);
  border-radius: 6rpx 0 0 6rpx;
  opacity: 0.6;
}
.q-tag {
  font-size: 20rpx;
  color: #4291F6;
  background: rgba(66, 145, 246, 0.15);
  padding: 2rpx 16rpx;
  border-radius: 30rpx;
  margin-left: auto;
}
.quick-filter-toggle {
  margin-top: 24rpx;
  padding-top: 20rpx;
  border-top: 1rpx solid rgba(255, 255, 255, 0.65);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18rpx;
}
.quick-filter-copy {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
  min-width: 0;
}
.quick-filter-title {
  font-size: 27rpx;
  font-weight: 600;
  color: #1a2a3a;
}
.quick-filter-sub {
  font-size: 21rpx;
  color: #718293;
}
.quick-filter-arrow {
  flex-shrink: 0;
  font-size: 23rpx;
  color: #165DFF;
}
.quick-filter-panel {
  padding-top: 20rpx;
}

.divider {
  height: 2rpx;
  background: rgba(255, 255, 255, 0.5);
  margin: 28rpx 0 24rpx 0;
}

.filter-header {
  display: flex;
  align-items: center;
  gap: 14rpx;
  margin-bottom: 14rpx;
}
.filter-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #1a2a3a;
}
.filter-badge {
  font-size: 20rpx;
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  color: #fff;
  padding: 2rpx 18rpx;
  border-radius: 30rpx;
}
.tags-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.tag {
  background: rgba(255, 255, 255, 0.65);
  border: 1rpx solid rgba(255, 255, 255, 0.8);
  border-radius: 60rpx;
  padding: 6rpx 16rpx;
  font-size: 22rpx;
  color: #1a2a3a;
  font-weight: 450;
  backdrop-filter: blur(4rpx);
  box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.03);
  pointer-events: auto;
  user-select: none;
  transition: all 0.2s ease;
}
.tag.active {
  background: rgba(66, 145, 246, 0.25);
  border-color: #4291F6;
  color: #4291F6;
  font-weight: 600;
  box-shadow: 0 0 0 2rpx rgba(66, 145, 246, 0.2);
}
.quick-start {
  margin-top: 22rpx;
  padding: 18rpx 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 18rpx;
  color: #FFFFFF;
  font-size: 26rpx;
  font-weight: 600;
  background: linear-gradient(135deg, #165DFF, #4291F6);
  box-shadow: 0 8rpx 20rpx rgba(22, 93, 255, 0.18);
}
.quick-start.disabled {
  color: #64748B;
  background: rgba(255, 255, 255, 0.56);
  box-shadow: none;
}
.quick-start-arrow { font-size: 30rpx; }

.new-reply-tip {
  position: fixed;
  left: 50%;
  bottom: 330rpx;
  transform: translateX(-50%);
  z-index: 50;
  padding: 12rpx 24rpx;
  color: #165DFF;
  font-size: 24rpx;
  background: rgba(255, 255, 255, 0.96);
  border: 1rpx solid #D6E4FF;
  border-radius: 40rpx;
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.12);
}

/* ===== 底部输入区域 ===== */
.input-area {
  flex-shrink: 0;
  padding: 16rpx 28rpx 12rpx 28rpx;
  background: rgba(255, 255, 255, 0.50);
  backdrop-filter: blur(30rpx);
  border-top: 1rpx solid rgba(255, 255, 255, 0.3);
  display: flex;
  /* 输入框会随行数增高,底部对齐才能让麦克风/发送键始终贴着下沿 */
  align-items: flex-end;
  gap: 12rpx;
  /* 左右留白收窄,把输入区拉宽(原为 60rpx,偏窄) */
  margin: 0 20rpx 160rpx 20rpx;
  border-radius: 60rpx;
  padding-bottom: calc(12rpx + env(safe-area-inset-bottom));
  padding-bottom: calc(12rpx + constant(safe-area-inset-bottom));
}
.input-main {
  flex: 1;
  min-width: 0;
  display: flex;
  position: relative;
}
.input-box {
  flex: 1;
  width: 100%;
  /* textarea + auto-height:高度随内容增长,给上下限避免无限撑高 */
  height: auto;
  min-height: 72rpx;
  max-height: 220rpx;
  background: rgba(255, 255, 255, 0.6);
  border: 1rpx solid rgba(255, 255, 255, 0.7);
  border-radius: 36rpx;
  padding: 16rpx 28rpx;
  font-size: 28rpx;
  line-height: 1.5;
  color: #1a2a3a;
  box-sizing: border-box;
}
/* uni-app H5 会把 textarea 包在 uni-textarea 里,内层也要能自动增高 */
.input-box .uni-textarea-textarea {
  height: auto !important;
  min-height: 40rpx;
  max-height: 188rpx;
}
.input-box::placeholder {
  color: #4a5a6a;
}
.input-count {
  position: absolute;
  right: 20rpx;
  bottom: 8rpx;
  font-size: 20rpx;
  color: #94A3B8;
  pointer-events: none;
}
/* 麦克风图标改为图片，保留原有尺寸和样式 */
.mic-icon {
  width: 72rpx;
  height: 50rpx;
  border-radius: 50%;
  flex-shrink: 0;
  /* 移除原有背景和文字颜色，由图片本身显示 */
  background: transparent !important;
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
  /* 输入区是 flex-end 对齐:图标比发送键矮 22rpx,不抬一下中心会偏下 */
  margin-bottom: 11rpx;
}
.send-icon {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
  color: #fff;
  flex-shrink: 0;
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
}
.mic-icon:active,
.send-icon:active {
  transform: scale(0.90);
}
.mic-disabled { opacity: 0.45; }
.send-icon.disabled {
  opacity: 0.45;
  box-shadow: none;
}
.send-icon.stop {
  background: #334155;
  box-shadow: 0 4rpx 16rpx rgba(51, 65, 85, 0.2);
}

.bottom-space {
  height: 20rpx;
}

/* ===== 历史对话侧边栏 ===== */
.drawer-mask {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.40);
  z-index: 999;
  animation: fadeIn 0.25s ease;
}
.drawer {
  position: fixed;
  top: 0;
  right: 0;
  width: 560rpx;
  max-width: 80%;
  height: 100vh;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(30rpx);
  z-index: 1000;
  transform: translateX(100%);
  transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
  display: flex;
  flex-direction: column;
  box-shadow: -8rpx 0 40rpx rgba(0, 0, 0, 0.12);
}
.drawer.open {
  transform: translateX(0);
  animation: drawerIn 0.24s cubic-bezier(0.32, 0.72, 0, 1);
}
.drawer-header {
  flex-shrink: 0;
  box-sizing: border-box;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 40rpx 32rpx 24rpx 32rpx;
  border-bottom: 2rpx solid rgba(0, 0, 0, 0.05);
  padding-top: calc(80rpx + env(safe-area-inset-top));
  padding-top: calc(80rpx + constant(safe-area-inset-top));
}
.drawer-title {
  font-size: 36rpx;
  font-weight: 700;
  color: #1a2a3a;
}
.drawer-actions {
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.drawer-new {
  font-size: 24rpx;
  color: #165DFF;
  background: rgba(22, 93, 255, 0.1);
  border-radius: 24rpx;
  padding: 8rpx 20rpx;
}
.drawer-new:active {
  opacity: 0.7;
}
.drawer-close {
  font-size: 40rpx;
  color: #8a9aa8;
  padding: 8rpx 16rpx;
  border-radius: 12rpx;
  transition: all 0.2s;
}
.drawer-close:active {
  background: rgba(0, 0, 0, 0.06);
  transform: scale(0.90);
}
.drawer-summary {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
  padding: 18rpx 28rpx 10rpx;
}
.drawer-local { font-size: 22rpx; color: #64748B; }
.drawer-sync-actions { display: flex; align-items: center; gap: 14rpx; flex-shrink: 0; }
.drawer-sync { font-size: 22rpx; color: #165DFF; padding: 6rpx 4rpx; }
.drawer-clear { flex-shrink: 0; font-size: 22rpx; color: #D92D20; padding: 6rpx 4rpx; }
.drawer-search {
  flex-shrink: 0;
  margin: 6rpx 20rpx 8rpx;
  min-height: 72rpx;
  display: flex;
  align-items: center;
  gap: 10rpx;
  padding: 0 18rpx;
  background: #F5F8FF;
  border: 1rpx solid #D6E4FF;
  border-radius: 18rpx;
  box-sizing: border-box;
}
.drawer-search-icon { font-size: 24rpx; color: #64748B; }
.drawer-search-input { flex: 1; min-width: 0; height: 64rpx; font-size: 26rpx; color: #1F2937; }
.drawer-search-clear { flex-shrink: 0; padding: 10rpx; font-size: 24rpx; color: #94A3B8; }
.drawer-list {
  flex: 1;
  width: 100%;
  box-sizing: border-box;
  padding: 8rpx 20rpx 20rpx 20rpx;
  overflow: hidden;
}
.drawer-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 20rpx;
  border-radius: 20rpx;
  background: rgba(255, 255, 255, 0.6);
  margin-bottom: 12rpx;
  transition: all 0.15s;
  border: 1rpx solid rgba(255, 255, 255, 0.8);
}
.drawer-item:active {
  background: rgba(66, 145, 246, 0.08);
  border-color: rgba(66, 145, 246, 0.2);
  transform: scale(0.98);
}
.drawer-item-left {
  display: flex;
  align-items: center;
  gap: 16rpx;
  flex: 1;
  min-width: 0;
}
.drawer-item-icon {
  font-size: 32rpx;
  flex-shrink: 0;
}
.drawer-item-info {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
  flex: 1;
  min-width: 0;
}
.drawer-item-title {
  font-size: 28rpx;
  color: #1a2a3a;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.drawer-item-time {
  font-size: 22rpx;
  color: #8a9aa8;
}
.drawer-item-right {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.drawer-item-delete {
  font-size: 22rpx;
  color: #D92D20;
  padding: 10rpx 8rpx;
  border-radius: 10rpx;
}
.drawer-item-delete:active { background: rgba(217, 45, 32, 0.08); }
.drawer-item-arrow {
  font-size: 36rpx;
  color: #c0c8d0;
  flex-shrink: 0;
  margin-left: 2rpx;
}
.drawer-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 120rpx 0;
}
.drawer-empty-icon {
  font-size: 80rpx;
  margin-bottom: 24rpx;
}
.drawer-empty-text {
  font-size: 30rpx;
  color: #6a7a8a;
  font-weight: 500;
}
.drawer-empty-sub {
  font-size: 24rpx;
  color: #9aabbb;
  margin-top: 8rpx;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes drawerIn {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}

/* ===== 【新增】强制锁定导航栏尺寸，与订单页完全一致 ===== */
.custom-tabbar {
  height: 115rpx !important;
}
.custom-tabbar .tab-icon {
  font-size: 46rpx !important;
}
.custom-tabbar .tab-text {
  font-size: 21rpx !important;
}

/* ===== 录音浮层（按住说话）===== */
.voice-mask {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.40);
  /* ⚠ 必须高于 .drawer-mask(999) 与 .drawer(1000)，否则浮层会被侧边栏盖住 */
  z-index: 1001;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fadeIn 0.25s ease;
}
.voice-card {
  width: 520rpx;
  padding: 48rpx 40rpx 40rpx;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(30rpx);
  -webkit-backdrop-filter: blur(30rpx);
  border-radius: 24rpx;
  box-shadow: 0 12rpx 40rpx rgba(15, 23, 42, 0.18);
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* 音量条：H5 用真实音量驱动高度；小程序端没有音量回调，走下面的循环动画 */
.voice-wave {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10rpx;
  height: 64rpx;
  margin-bottom: 28rpx;
}
.voice-bar {
  width: 6rpx;
  height: 20rpx;
  border-radius: 3rpx;
  background: linear-gradient(180deg, #4291F6, #6EB5FF);
}
.voice-wave.is-animated .voice-bar {
  animation: voiceWave 0.9s ease-in-out infinite;
}
.voice-wave.is-animated .voice-bar:nth-child(2) { animation-delay: 0.10s; }
.voice-wave.is-animated .voice-bar:nth-child(3) { animation-delay: 0.20s; }
.voice-wave.is-animated .voice-bar:nth-child(4) { animation-delay: 0.30s; }
.voice-wave.is-animated .voice-bar:nth-child(5) { animation-delay: 0.40s; }
.voice-wave.is-animated .voice-bar:nth-child(6) { animation-delay: 0.50s; }
.voice-wave.is-animated .voice-bar:nth-child(7) { animation-delay: 0.60s; }
@keyframes voiceWave {
  0%, 100% { height: 16rpx; }
  50%      { height: 56rpx; }
}

/* 实时文字：只有「小程序 + 同声传译插件」链路会有内容 */
.voice-live {
  display: block;
  width: 100%;
  min-height: 80rpx;
  text-align: center;
  font-size: 28rpx;
  line-height: 1.6;
  color: #1F2937;
  margin-bottom: 16rpx;
}
.voice-live.is-empty { color: #9AABBB; }

.voice-timer {
  font-size: 22rpx;
  color: #9AABBB;
  margin-bottom: 12rpx;
}

.voice-hint {
  font-size: 24rpx;
  color: #6B7280;
}
.voice-hint.is-cancel { color: #FF4D4F; }

/* 录音中：浮层已承担主要反馈，图标只做弱化即可 */
.mic-icon.mic-recording {
  opacity: 0.6;
}
</style>
<style scoped lang="scss">
/* ===== 小召·召唤结果流 ===== */
.summon-scroll {
  position: fixed;
  left: 24rpx;
  right: 24rpx;
  bottom: 150rpx;
  max-height: 52vh;
  z-index: 40;
  background: rgba(255, 255, 255, 0.96);
  border-radius: 24rpx;
  box-shadow: 0 12rpx 40rpx rgba(15, 23, 42, 0.18);
}
.summon-wrap { padding: 24rpx; }
.summon-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.summon-badge { font-size: 26rpx; font-weight: 700; color: #165DFF; background: rgba(22, 93, 255, 0.1); border-radius: 20rpx; padding: 6rpx 20rpx; }
.summon-close { font-size: 24rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.summon-scene { display: flex; align-items: center; gap: 16rpx; background: #F0F6FF; border: 1px solid #D6E4FF; border-radius: 16rpx; padding: 18rpx; }
.summon-scene-icon { font-size: 44rpx; }
.summon-scene-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.summon-scene-name { font-size: 30rpx; font-weight: 700; color: #1F2937; }
.summon-scene-desc { font-size: 22rpx; color: #6B7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.summon-scene-go { font-size: 24rpx; color: #165DFF; flex-shrink: 0; }
.summon-answer { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16rpx; padding: 20rpx; margin-bottom: 16rpx; }
.summon-answer-text { font-size: 26rpx; line-height: 1.7; color: #1F2937; }
.summon-sub { display: block; font-size: 24rpx; color: #6B7280; margin: 20rpx 0 10rpx; }
.summon-machine { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14rpx; padding: 16rpx 20rpx; margin-bottom: 10rpx; }
.summon-machine-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6rpx; }
.summon-machine-name { font-size: 26rpx; color: #1F2937; font-weight: 600; }
.summon-machine-reason { font-size: 22rpx; line-height: 1.45; color: #64748B; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.summon-machine-price { font-size: 24rpx; color: #165DFF; font-weight: 700; }
.summon-alts { margin-top: 6rpx; }
.summon-alt { display: flex; align-items: center; justify-content: space-between; padding: 14rpx 6rpx; border-bottom: 1px solid #F1F5F9; font-size: 26rpx; color: #334155; }
.summon-alt-arrow { color: #CBD5E1; }
.summon-empty-text { font-size: 26rpx; color: #6B7280; line-height: 1.6; }
.custom-cta-card {
  width: 100%;
  margin-top: 16rpx;
  padding: 20rpx 22rpx;
  display: flex;
  align-items: center;
  gap: 16rpx;
  background: linear-gradient(135deg, rgba(240, 246, 255, 0.98), rgba(255, 255, 255, 0.98));
  border: 1px solid #D6E4FF;
  border-radius: 18rpx;
  box-sizing: border-box;
}
.custom-cta-card:active { transform: scale(0.99); }
.custom-cta-copy { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6rpx; }
.custom-cta-title { font-size: 26rpx; font-weight: 600; color: #1F2937; }
.custom-cta-sub { font-size: 22rpx; line-height: 1.5; color: #64748B; }
.custom-cta-arrow { flex-shrink: 0; font-size: 38rpx; color: #165DFF; }
</style>
