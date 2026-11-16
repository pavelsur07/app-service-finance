import { IconPlus as TablerPlus, type IconProps } from '@tabler/icons-react';
export interface DsIconProps extends Omit<IconProps, 'size' | 'stroke'> {
  size?: 16 | 18 | 20;
}
/** Owner-approved icon scale/stroke, with named imports for tree shaking. */
export function IconPlus({ size = 18, ...props }: DsIconProps) {
  return <TablerPlus {...props} size={size} stroke={2} />;
}
