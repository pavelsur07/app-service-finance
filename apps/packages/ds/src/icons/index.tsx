import { IconPlus as TablerPlus, type IconProps } from '@tabler/icons-react';
export interface DsIconProps extends Omit<IconProps, 'size' | 'stroke'> {
  size?: 16 | 18 | 20;
}
/** Owner-approved icon scale/stroke, with named imports for tree shaking. */
export function IconPlus({ size = 18, ...props }: DsIconProps) {
  return <TablerPlus {...props} size={size} stroke={2} />;
}
import {
  IconHome as TablerHome,
  IconInfoCircle as TablerInfoCircle,
  IconUserCircle as TablerUserCircle,
  IconShield as TablerShield,
} from '@tabler/icons-react';
export function IconHome({ size = 18, ...props }: DsIconProps) {
  return <TablerHome {...props} size={size} stroke={2} />;
}
export function IconInfoCircle({ size = 18, ...props }: DsIconProps) {
  return <TablerInfoCircle {...props} size={size} stroke={2} />;
}
export function IconUserCircle({ size = 18, ...props }: DsIconProps) {
  return <TablerUserCircle {...props} size={size} stroke={2} />;
}
export function IconShield({ size = 18, ...props }: DsIconProps) {
  return <TablerShield {...props} size={size} stroke={2} />;
}
