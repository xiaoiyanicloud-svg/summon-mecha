import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

// 执行实际页面方法和实际持久化辅助代码；只替换 uni 存储与 HTTP 传输。
function fixture({ decision = 'accept', deferred = false } = {}) {
  const storage = new Map([['token', 'qa-only'], ['customer', { id: 1 }]]), calls = [], toasts = [];
  const uni = {
    getStorageSync: key => structuredClone(storage.get(key)),
    setStorageSync: (key, value) => storage.set(key, structuredClone(value)),
    showToast: value => toasts.push(value), navigateBack() {}, navigateTo() {}
  };
  const project = { id: 2, project_no: 'QA-PROJECT', state: 'delivering', row_version: 13, tasks: [], acceptance_template: [], acceptances: [], changes: [{ id: 7, state: 'pending_customer_acceptance', price_delta_minor: 100 }], tickets: [] };
  let release;
  const pending = deferred ? new Promise(resolve => { release = resolve; }) : null;
  const api = {
    project: async () => ({ data: structuredClone(project) }),
    projectChangeDecision: async (id, changeId, body, access, key) => {
      calls.push({ path: `/api/scene-projects/${id}/changes/${changeId}/decision`, body: structuredClone(body), key });
      if (pending) await pending;
      if (calls.length === 1) {
        project.row_version = 14; project.changes[0].state = decision === 'accept' ? 'applied' : 'declined';
        throw new Error('QA 服务端已提交，响应丢失');
      }
      return { data: { id: changeId, state: project.changes[0].state } };
    },
    projectAction: async (id, action, body, access, key) => {
      calls.push({ path: `/api/scene-projects/${id}/${action}`, body: structuredClone(body), key });
      if (calls.length === 1) throw new Error('QA 原操作响应丢失');
      return { data: { id, state: 'requested' } };
    }
  };
  const helper = fs.readFileSync('utils/scene-request.js', 'utf8').replace(/^import .*$/gm, '').replace(/^export /gm, '');
  const helpers = new Function('sceneWorkflowApi', 'TOKEN_KEY', 'CUSTOMER_KEY', 'uni', helper + '\nreturn {sceneAccountScope,sceneLocalState,submitSceneCommand,scenePendingCommands,sceneStatusLabel}')(api, 'token', 'customer', uni);
  const script = fs.readFileSync('pages/scene/project.vue', 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return');
  const component = new Function('StateView', 'sceneWorkflowApi', 'uni', ...Object.keys(helpers), script)({}, api, uni, ...Object.values(helpers));
  const mount = () => {
    const vm = component.data();
    for (const [name, fn] of Object.entries(component.methods)) vm[name] = fn.bind(vm);
    vm.id = 2; vm.project = structuredClone(project); vm.scope = helpers.sceneAccountScope();
    return vm;
  };
  return { ...helpers, mount, api, calls, project, storage, toasts, release };
}

for (const decision of ['accept', 'decline']) test(`P1 ${decision} 回执丢失后重载，沿用原决定、版本、接口及幂等键`, async () => {
  const f = fixture({ decision });
  await f.mount().decideChange(7, decision);
  const vm = f.mount(); await vm.load();
  assert.equal(vm.project.row_version, 14);
  assert.equal(vm.pendingCommands.length, 1);
  assert.equal(vm.pendingLabel(vm.pendingCommands[0].slot), '变更确认');
  await vm.retryPending(vm.pendingCommands[0]);
  assert.equal(f.calls.length, 2);
  assert.deepEqual(f.calls[1], f.calls[0]);
  assert.deepEqual(f.calls[1].body, { decision, row_version: 13 });
  assert.equal(f.calls[1].path, '/api/scene-projects/2/changes/7/decision');
  assert.deepEqual(f.scenePendingCommands('project:2:'), []);
  assert.equal(vm.busy, false);
});

test('P1 未确认回执阻断新变更决定及取消，不依赖过期的页面 pending 数组', async () => {
  const f = fixture(), vm = f.mount();
  await vm.decideChange(7, 'accept');
  vm.pendingCommands = [];
  await vm.decideChange(8, 'decline'); await vm.send('cancel', { reason: 'QA 新请求' });
  assert.equal(f.calls.length, 1);
  assert.equal(f.scenePendingCommands('project:2:').length, 1);
});

test('P1 普通项目命令恢复仍沿用原接口、原载荷和幂等键', async () => {
  const f = fixture(), vm = f.mount();
  await vm.send('cancel', { reason: 'QA 保留原原因' });
  await vm.load(); await vm.retryPending(vm.pendingCommands[0]);
  assert.equal(f.calls.length, 2); assert.deepEqual(f.calls[1], f.calls[0]);
  assert.equal(f.calls[1].path, '/api/scene-projects/2/cancel');
  assert.deepEqual(f.scenePendingCommands('project:2:'), []);
});

test('P1 未知操作或跨项目 slot 不转换成数字 action，也不发请求', async () => {
  const f = fixture(), vm = f.mount();
  for (const slot of ['project:2:7', 'project:3:change-decision:7', 'project:2:change-decision:0', 'project:2:change-decision:9007199254740992']) {
    await vm.retryPending({ slot, payload: { decision: 'accept', row_version: 13 } });
  }
  assert.equal(f.calls.length, 0); assert.equal(f.toasts.length, 4);
});

test('P1 账号切换时旧回执按主体隔离，异步完成不污染新主体且解除 busy', async () => {
  const f = fixture({ deferred: true }), vm = f.mount();
  const operation = vm.decideChange(7, 'accept');
  assert.equal(vm.busy, true);
  f.storage.set('customer', { id: 2 });
  f.release(); await operation;
  assert.equal(vm.busy, false);
  assert.deepEqual(f.scenePendingCommands('project:2:'), []);
  assert.equal(f.toasts.length, 0);
  f.storage.set('customer', { id: 1 });
  assert.equal(f.scenePendingCommands('project:2:').length, 1);
});

test('P1 确定的版本冲突刷新最新项目，旧未知回执转为明确拒绝', async () => {
  const f = fixture(), vm = f.mount();
  f.project.row_version = 15;
  f.api.projectChangeDecision = async () => { const e = new Error('QA 版本冲突'); e.statusCode = 409; throw e; };
  await vm.decideChange(7, 'accept');
  assert.equal(vm.project.row_version, 15);
  assert.deepEqual(f.scenePendingCommands('project:2:'), []);
  assert.equal(f.sceneLocalState().pending['project:2:change-decision:7'].state, 'rejected');
  assert.equal(vm.busy, false);
});
