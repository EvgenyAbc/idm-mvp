import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'

export type ModalEntry = {
  key: string
  title: string
  content: ReactNode
  ariaLabel?: string
  onClose?: () => void
}

type ModalContextValue = {
  stack: ModalEntry[]
  openModal: (entry: ModalEntry) => void
  closeModal: (key: string) => void
  closeTopModal: () => void
}

const ModalContext = createContext<ModalContextValue | null>(null)

export function ModalProvider({ children }: { children: ReactNode }) {
  const [stack, setStack] = useState<ModalEntry[]>([])
  const lastFocusedRef = useRef<HTMLElement | null>(null)

  const openModal = useCallback((entry: ModalEntry) => {
    setStack((prev) => {
      const withoutSameKey = prev.filter((item) => item.key !== entry.key)
      if (withoutSameKey.length === 0) {
        lastFocusedRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
      }
      return [...withoutSameKey, entry]
    })
  }, [])

  const closeModal = useCallback((key: string) => {
    setStack((prev) => {
      const target = prev.find((item) => item.key === key)
      target?.onClose?.()
      const next = prev.filter((item) => item.key !== key)
      if (next.length === 0) {
        lastFocusedRef.current?.focus()
        lastFocusedRef.current = null
      }
      return next
    })
  }, [])

  const closeTopModal = useCallback(() => {
    setStack((prev) => {
      if (prev.length === 0) return prev
      const top = prev[prev.length - 1]
      top.onClose?.()
      const next = prev.slice(0, -1)
      if (next.length === 0) {
        lastFocusedRef.current?.focus()
        lastFocusedRef.current = null
      }
      return next
    })
  }, [])

  const value = useMemo<ModalContextValue>(
    () => ({
      stack,
      openModal,
      closeModal,
      closeTopModal,
    }),
    [stack, openModal, closeModal, closeTopModal]
  )

  return (
    <ModalContext.Provider value={value}>
      {children}
    </ModalContext.Provider>
  )
}

export function useModalContext(): ModalContextValue {
  const ctx = useContext(ModalContext)
  if (!ctx) {
    throw new Error('useModalContext must be used within ModalProvider')
  }
  return ctx
}
