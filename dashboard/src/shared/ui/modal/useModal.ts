import { useModalContext, type ModalEntry } from './ModalProvider'

export function useModal() {
  const { openModal, closeModal, closeTopModal } = useModalContext()

  return {
    openModal: (entry: ModalEntry) => openModal(entry),
    closeModal,
    closeTopModal,
  }
}
