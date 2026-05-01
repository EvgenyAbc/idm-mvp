import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useModalContext } from './ModalProvider'

export function ModalHost() {
  const { stack, closeModal, closeTopModal } = useModalContext()
  const topModalRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (stack.length === 0) return
    topModalRef.current?.focus()
  }, [stack])

  useEffect(() => {
    if (stack.length === 0) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        closeTopModal()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [stack, closeTopModal])

  if (stack.length === 0) return null

  return createPortal(
    <>
      {stack.map((modal, idx) => {
        const isTop = idx === stack.length - 1
        return (
          <div key={modal.key} className="modal-overlay" role="presentation" onClick={() => isTop && closeModal(modal.key)} style={{ zIndex: 20 + idx }}>
            <div
              ref={isTop ? topModalRef : null}
              className="modal-card"
              role="dialog"
              aria-modal="true"
              aria-label={modal.ariaLabel ?? modal.title}
              tabIndex={-1}
              onClick={(event) => event.stopPropagation()}
            >
              {modal.content}
            </div>
          </div>
        )
      })}
    </>,
    document.body
  )
}
