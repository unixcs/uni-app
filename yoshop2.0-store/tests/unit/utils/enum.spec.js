import Enum from '@/common/enum/enum'
import { getEnumName } from '@/utils/enum'

const StatusEnum = new Enum([
  { key: 'PENDING', name: '待处理', value: 10 },
  { key: 'DONE', name: '已完成', value: 20 }
])

describe('getEnumName', () => {
  test.each([
    [10, '待处理'],
    [20, '已完成'],
    ['PENDING', '待处理']
  ])('returns the configured name for %p', (value, expected) => {
    expect(getEnumName(StatusEnum, value)).toBe(expected)
  })

  test.each([null, undefined, 0, 999, 'legacy', 'toString'])('uses a placeholder for unknown value %p', value => {
    expect(getEnumName(StatusEnum, value)).toBe('--')
  })

  test('supports an explicit fallback and missing enum object', () => {
    expect(getEnumName(StatusEnum, 'legacy', '未知状态')).toBe('未知状态')
    expect(getEnumName(null, 10, '未知状态')).toBe('未知状态')
  })
})
