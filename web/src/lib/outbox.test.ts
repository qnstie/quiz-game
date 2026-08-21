import { describe, expect, it, beforeEach } from 'vitest'
import { enqueueAnswer, readOutbox, dequeueAnswer, writeOutbox } from './outbox'

const store = new Map<string, string>()

beforeEach(() => {
  store.clear()
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => {
        store.set(k, v)
      },
      removeItem: (k: string) => {
        store.delete(k)
      },
      clear: () => store.clear(),
    },
  })
})

describe('outbox', () => {
  it('enqueues and dequeues answers', () => {
    writeOutbox([])
    enqueueAnswer('q1', 'o1')
    enqueueAnswer('q1', 'o2')
    expect(readOutbox()).toEqual([{ quizId: 'q1', optionId: 'o2', attempts: 0 }])
    dequeueAnswer('q1')
    expect(readOutbox()).toEqual([])
  })
})
