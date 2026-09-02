export type PhotoConsentStatusKind = 'pending' | 'granted' | 'denied'

export type PhotoConsentStatus = {
  status: PhotoConsentStatusKind
  checkInLabel: string
  selfServiceMessage: string
  answered?: number
  peopleCount?: number | null
}

const HELPER_COPY: Record<PhotoConsentStatusKind, {checkInLabel: string; selfServiceMessage: string}> = {
  pending: {
    checkInLabel: 'Fotoerlaubnis fehlt',
    selfServiceMessage: 'Bitte nicht vergessen, die Fotoerlaubnis zu schicken.',
  },
  granted: {
    checkInLabel: 'Fotoerlaubnis liegt vor',
    selfServiceMessage: 'Danke für die Fotoerlaubnis.',
  },
  denied: {
    checkInLabel: 'Fotoerlaubnis verweigert',
    selfServiceMessage:
      'Schade, dass du die Fotoerlaubnis nicht erteilt hast. Wenn du deine Meinung noch ändern möchtest, melde dich einfach.',
  },
}

export function photoConsentStatusForVolunteer(consent: boolean | null | undefined): PhotoConsentStatus {
  if (consent === true) {
    return {status: 'granted', ...HELPER_COPY.granted}
  }
  if (consent === false) {
    return {status: 'denied', ...HELPER_COPY.denied}
  }
  return {status: 'pending', ...HELPER_COPY.pending}
}

export function photoConsentStatusForTeam(
  counts: Record<string, number> | null | undefined,
  peopleCount: number | null | undefined,
): PhotoConsentStatus {
  const yes = Number(counts?.yes ?? 0)
  const no = Number(counts?.no ?? 0)
  const answered = yes + no

  if (no >= 1) {
    return {
      status: 'denied',
      checkInLabel: 'Mindestens eine Fotoerlaubnis verweigert',
      selfServiceMessage:
        'Schade, dass nicht alle die Fotoerlaubnis gegeben haben. Wenn sich das noch ändert, melde dich einfach.',
      answered,
      peopleCount: peopleCount ?? null,
    }
  }

  if (peopleCount != null && peopleCount > 0 && yes === peopleCount) {
    return {
      status: 'granted',
      checkInLabel: 'Alle Fotoerlaubnisse liegen vor',
      selfServiceMessage: 'Alle Fotoerlaubnisse liegen vor. Danke!',
      answered,
      peopleCount,
    }
  }

  const y = peopleCount ?? 0
  return {
    status: 'pending',
    checkInLabel: 'Es fehlen Fotoerlaubnisse',
    selfServiceMessage: `Bisher liegen ${answered} von ${y} Fotoerlaubnissen vor. Bitte nicht vergessen, die restlichen zu schicken.`,
    answered,
    peopleCount: peopleCount ?? null,
  }
}

export function photoConsentStatusClass(status: PhotoConsentStatusKind): string {
  return `photo-consent--${status}`
}
