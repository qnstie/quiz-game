const OUTBOX_KEY = 'fq_answer_outbox'

export type OutboxItem = { quizId: string; optionId: string; attempts: number }

export function readOutbox(): OutboxItem[] {
  try {
    return JSON.parse(localStorage.getItem(OUTBOX_KEY) || '[]') as OutboxItem[]
  } catch {
    return []
  }
}

export function writeOutbox(items: OutboxItem[]) {
  localStorage.setItem(OUTBOX_KEY, JSON.stringify(items))
}

export function enqueueAnswer(quizId: string, optionId: string) {
  const items = readOutbox().filter((i) => i.quizId !== quizId)
  items.push({ quizId, optionId, attempts: 0 })
  writeOutbox(items)
}

export function dequeueAnswer(quizId: string) {
  writeOutbox(readOutbox().filter((i) => i.quizId !== quizId))
}
