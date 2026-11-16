import { Overlay, type ModalProps } from '../Modal/Modal';
export interface DrawerProps extends Omit<ModalProps, 'size'> {
  size?: never;
}
export function Drawer(props: DrawerProps) {
  return <Overlay {...props} drawer />;
}
