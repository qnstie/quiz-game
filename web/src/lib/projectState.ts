/** Project states where admin/agent may edit content. */
export function isContentEditable(state?: string | null): boolean {
  return state === 'SETUP' || state === 'TEST'
}

/** Project states where participants may join and answer. */
export function isParticipantLive(state?: string | null): boolean {
  return state === 'ACTIVE' || state === 'TEST'
}
