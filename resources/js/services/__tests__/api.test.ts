import { describe, it, expect, vi, beforeEach } from 'vitest'

const TOKEN_KEY = 'lumina_token'

const mockInstance = vi.hoisted(() => ({
  get: vi.fn().mockResolvedValue({ data: {} }),
  post: vi.fn().mockResolvedValue({ data: {} }),
  delete: vi.fn().mockResolvedValue({ data: {} }),
  put: vi.fn().mockResolvedValue({ data: {} }),
  interceptors: {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  },
}))

vi.mock('axios', () => ({
  default: {
    create: vi.fn(() => mockInstance),
  },
}))

beforeEach(() => {
  localStorage.clear()
})

describe('api service', () => {
  it('exports get, post, del, put, upload functions', async () => {
    const mod = await import('../api')
    expect(typeof mod.get).toBe('function')
    expect(typeof mod.post).toBe('function')
    expect(typeof mod.del).toBe('function')
    expect(typeof mod.put).toBe('function')
    expect(typeof mod.upload).toBe('function')
  })

  it('get passes params through to instance', async () => {
    const { get } = await import('../api')
    await get('/test', { page: 1 })
    expect(mockInstance.get).toHaveBeenCalledWith('/test', { params: { page: 1 } })
  })

  it('post passes data through to instance', async () => {
    const { post } = await import('../api')
    await post('/chat', { question: 'hi' })
    expect(mockInstance.post).toHaveBeenCalledWith('/chat', { question: 'hi' })
  })

  it('del passes URL through to instance', async () => {
    const { del } = await import('../api')
    await del('/sessions/abc')
    expect(mockInstance.delete).toHaveBeenCalledWith('/sessions/abc')
  })
})
