import { describe, it, expect, vi, beforeEach } from 'vitest'

const TOKEN_KEY = 'lumina_token'

beforeEach(() => {
  localStorage.clear()
})

function makeReader(lines: string[]) {
  const encoder = new TextEncoder()
  const chunks = [encoder.encode(lines.join('\n') + '\n\n')]
  let i = 0
  return {
    read: vi.fn(async () => {
      if (i >= chunks.length) return { done: true, value: undefined }
      return { done: false, value: chunks[i++] }
    }),
    cancel: vi.fn(),
  }
}

function makeStreamedResponse(ok: boolean, lines: string[], status = 200) {
  const reader = makeReader(lines)
  return {
    ok,
    status,
    body: { getReader: () => reader },
    json: () => Promise.resolve({ message: 'Invalid' }),
  } as unknown as Response
}

describe('chatService.askStreaming', () => {
  it('calls onDone when stream completes', async () => {
    localStorage.setItem(TOKEN_KEY, 'test-token')

    const response = makeStreamedResponse(true, [
      'data: {"type":"done","session_id":"ses_123"}',
    ])
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response)

    const { chatService } = await import('../chatService')

    const onDone = vi.fn()
    const onError = vi.fn()
    const onChunk = vi.fn()
    const onSources = vi.fn()

    chatService.askStreaming('hello', 'ses_abc', { onDone, onError, onChunk, onSources })

    await vi.waitFor(() => {
      expect(onDone).toHaveBeenCalledWith('ses_123', expect.objectContaining({}))
      expect(onError).not.toHaveBeenCalled()
    })
  })

  it('calls onChunk for chunk events', async () => {
    localStorage.setItem(TOKEN_KEY, 'test-token')

    const response = makeStreamedResponse(true, [
      'data: {"type":"chunk","content":"Hello"}',
      'data: {"type":"chunk","content":" world"}',
      'data: {"type":"done","session_id":"ses_123"}',
    ])
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response)

    const { chatService } = await import('../chatService')

    const onChunk = vi.fn()
    const onDone = vi.fn()

    chatService.askStreaming('hello', undefined, {
      onDone,
      onError: vi.fn(),
      onChunk,
      onSources: vi.fn(),
      onStatus: vi.fn(),
    })

    await vi.waitFor(() => {
      expect(onChunk).toHaveBeenCalledTimes(2)
      expect(onChunk).toHaveBeenNthCalledWith(1, 'Hello')
      expect(onChunk).toHaveBeenNthCalledWith(2, ' world')
      expect(onDone).toHaveBeenCalledWith('ses_123', expect.objectContaining({}))
    })
  })

  it('calls onError when response fails', async () => {
    const response = makeStreamedResponse(false, [], 422)
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response)

    const { chatService } = await import('../chatService')

    const onError = vi.fn()

    chatService.askStreaming('hello', undefined, {
      onDone: vi.fn(),
      onError,
      onChunk: vi.fn(),
      onSources: vi.fn(),
      onStatus: vi.fn(),
    })

    await vi.waitFor(() => {
      expect(onError).toHaveBeenCalledWith('Invalid')
    })
  })

  it('returns an AbortController that aborts the fetch', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(
      () => new Promise<Response>(() => {}),
    )

    const { chatService } = await import('../chatService')

    const controller = chatService.askStreaming('hello', undefined, {
      onDone: vi.fn(),
      onError: vi.fn(),
      onChunk: vi.fn(),
      onSources: vi.fn(),
      onStatus: vi.fn(),
    })

    controller.abort()

    await vi.waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledWith(
        '/api/chat',
        expect.objectContaining({ signal: controller.signal }),
      )
    })
  })
})
