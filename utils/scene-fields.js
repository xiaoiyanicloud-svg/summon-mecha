export function sceneFactLabel(field, value) {
  if (value === null || value === undefined || value === '') return '待核实'
  if (field.type === 'boolean') return value === true || value === 1 ? '是' : '否'
  if (field.type === 'choice') return (field.options || []).find(option => option.value === value)?.label || '待核实'
  return String(value) + (field.unit ? ' ' + field.unit : '')
}

// 未知和空白保留为空；不通过 Number('') 把未填写内容变为 0。
export function normalizeSceneFacts(schema, values) {
  const facts = {}
  for (const field of schema.fields || []) {
    const value = values[field.key]
    if (value === undefined) continue
    if (value === null || value === '') { facts[field.key] = null; continue }
    if (field.type === 'integer' && (!/^\d+$/.test(String(value)) || !Number.isSafeInteger(Number(value)))) throw new Error(field.label + '须为整数')
    if (field.type === 'decimal' && !/^\d{1,9}(?:\.\d{1,3})?$/.test(String(value))) throw new Error(field.label + '最多保留三位小数')
    facts[field.key] = field.type === 'integer' ? Number(value) : value
  }
  return facts
}
