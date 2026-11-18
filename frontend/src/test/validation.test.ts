import { describe, expect, it } from 'vitest'
import { loginInputSchema, registerInputSchema } from '../lib/api/schemas'
import { formatKes } from '../lib/formatting'

describe('loginInputSchema', () => {
  it('accepts valid credentials', () => {
    expect(loginInputSchema.safeParse({ email: 'a@b.co', password: 'x' }).success).toBe(true)
  })
  it('rejects invalid email', () => {
    expect(loginInputSchema.safeParse({ email: 'nope', password: 'x' }).success).toBe(false)
  })
  it('rejects empty password', () => {
    expect(loginInputSchema.safeParse({ email: 'a@b.co', password: '' }).success).toBe(false)
  })
})

describe('registerInputSchema', () => {
  const valid = {
    name: 'Amina Odhiambo',
    email: 'amina@example.co.ke',
    password: 'stocktake1',
    password_confirmation: 'stocktake1',
  }
  it('accepts a valid registration', () => {
    expect(registerInputSchema.safeParse(valid).success).toBe(true)
  })
  it('rejects short passwords', () => {
    const result = registerInputSchema.safeParse({ ...valid, password: 'a1', password_confirmation: 'a1' })
    expect(result.success).toBe(false)
  })
  it('rejects passwords without a number', () => {
    const result = registerInputSchema.safeParse({ ...valid, password: 'abcdefgh', password_confirmation: 'abcdefgh' })
    expect(result.success).toBe(false)
  })
  it('rejects mismatched confirmation', () => {
    const result = registerInputSchema.safeParse({ ...valid, password_confirmation: 'different1' })
    expect(result.success).toBe(false)
    if (!result.success) {
      expect(result.error.issues.some((i) => i.path[0] === 'password_confirmation')).toBe(true)
    }
  })
})

describe('formatKes', () => {
  it('formats KES with two decimals', () => {
    expect(formatKes(2000)).toMatch(/2,000\.00/)
  })
  it('accepts decimal strings from the API', () => {
    expect(formatKes('100.50')).toMatch(/100\.50/)
  })
  it('handles invalid input gracefully', () => {
    expect(formatKes('not-a-number')).toBe('—')
  })
})