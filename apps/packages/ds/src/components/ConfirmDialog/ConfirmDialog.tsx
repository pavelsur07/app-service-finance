import { useRef, type ReactNode } from 'react';
import { Modal, type ModalProps } from '../Modal/Modal';
import { Button } from '../Button/Button';
export interface ConfirmDialogProps extends Omit<
  ModalProps,
  'size' | 'footer' | 'dismissible' | 'showClose' | 'initialFocusRef'
> {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  busy?: boolean;
  destructive?: boolean;
}
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  children,
  confirmLabel = 'Подтвердить',
  cancelLabel = 'Отмена',
  busy = false,
  destructive = false,
  className = '',
  ...props
}: ConfirmDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  return (
    <Modal
      {...props}
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      size="sm"
      showClose={false}
      dismissible={!busy}
      initialFocusRef={cancel}
      aria-busy={busy || undefined}
      className={`ds-confirm ${className}`}
      footer={
        <>
          <Button
            ref={cancel}
            variant="secondary"
            disabled={busy}
            onClick={onClose}
          >
            {cancelLabel}
          </Button>
          <Button
            variant={destructive ? 'danger' : 'primary'}
            loading={busy}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      {children}
    </Modal>
  );
}
