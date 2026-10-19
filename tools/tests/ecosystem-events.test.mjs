import test from 'node:test'
import assert from 'node:assert/strict'
import {
  normalizeEvent,
  normalizeEventList,
  eventRegistrationState,
  eventRegistrationFeedback,
} from '../../utils/ecosystem-events.js'

test('empty event list stays empty instead of falling back to samples', () => {
  assert.deepEqual(normalizeEventList({ data: [] }), [])
})

test('malformed event payload is an error, not an empty state', () => {
  assert.throws(() => normalizeEventList({ data: null }), /格式异常/)
  assert.throws(() => normalizeEventList({ data: [{ name: '没有稳定 ID' }] }), /格式异常/)
})

test('only records with stable id and name survive normalization', () => {
  assert.equal(normalizeEvent({ id: 0, name: 'x' }), null)
  assert.equal(normalizeEvent({ id: 7, name: '  ' }), null)
  assert.equal(normalizeEvent({ id: 7, name: '真实赛事' }).id, 7)
})

test('registration is disabled unless backend explicitly says can_register', () => {
  assert.deepEqual(
    eventRegistrationState({ id: 7, status: 'registering', canRegister: false }),
    { enabled: false, reason: '站内报名暂未开放。' },
  )
  assert.deepEqual(
    eventRegistrationState({ id: 7, status: 'registering', canRegister: true }),
    { enabled: true, reason: '' },
  )
})

test('ended or non-registering events never expose a registration action', () => {
  assert.deepEqual(
    eventRegistrationState({ id: 7, status: 'ended', canRegister: true }),
    { enabled: false, reason: '该赛事已结束报名。' },
  )
  assert.deepEqual(
    eventRegistrationState({ id: 7, status: 'upcoming', canRegister: true }),
    { enabled: false, reason: '当前不在报名阶段。' },
  )
  // status 缺失/未知也不能被 can_register=true 绕过
  assert.deepEqual(
    eventRegistrationState({ id: 7, status: '', canRegister: true }),
    { enabled: false, reason: '当前不在报名阶段。' },
  )
})

test('backend registration_reason is shown verbatim when registration is closed', () => {
  assert.deepEqual(
    eventRegistrationState({
      id: 7,
      status: 'registering',
      canRegister: false,
      registrationReason: '测试期站内报名停写。',
    }),
    { enabled: false, reason: '测试期站内报名停写。' },
  )
})

test('registration feedback only accepts explicit success=true with a non-empty message', () => {
  assert.deepEqual(
    eventRegistrationFeedback({ success: true, message: '报名成功' }),
    { success: true, message: '报名成功' },
  )
})

test('missing or malformed feedback is reported as unknown, never as success', () => {
  const unknown = { success: false, message: '提交结果未知，请稍后查回报名状态。' }

  assert.deepEqual(eventRegistrationFeedback(undefined), unknown)
  assert.deepEqual(eventRegistrationFeedback(null), unknown)
  assert.deepEqual(eventRegistrationFeedback({}), unknown)
  assert.deepEqual(eventRegistrationFeedback({ success: 'true', message: '报名成功' }), unknown)
  assert.deepEqual(eventRegistrationFeedback({ success: true, message: '   ' }), unknown)
  assert.deepEqual(eventRegistrationFeedback({ success: true, message: 123 }), unknown)
})
