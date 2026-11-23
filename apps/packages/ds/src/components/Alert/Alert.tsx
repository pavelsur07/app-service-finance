import type { ComponentPropsWithRef, ReactNode } from 'react';
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconCircleCheck,
  IconInfoCircle,
} from '@tabler/icons-react';
export const feedbackTones = {
  success: 'border-success-border bg-success-bg text-success-text',
  danger: 'border-danger-border bg-danger-bg text-danger-text',
  warning: 'border-warning-border bg-warning-bg text-warning-text',
  info: 'border-info-border bg-info-bg text-info-text',
};
export type FeedbackTone = keyof typeof feedbackTones;
export const feedbackIcons = {
  success: IconCircleCheck,
  danger: IconAlertCircle,
  warning: IconAlertTriangle,
  info: IconInfoCircle,
};
export interface AlertProps extends Omit<
  ComponentPropsWithRef<'div'>,
  'title'
> {
  tone?: FeedbackTone;
  title?: ReactNode;
}
export function Alert({
  tone = 'info',
  title,
  className = '',
  children,
  role,
  ...props
}: AlertProps) {
  const Icon = feedbackIcons[tone];
  return (
    <div
      {...props}
      role={role ?? (tone === 'danger' ? 'alert' : 'status')}
      className={`flex items-start gap-3 rounded-lg border px-4 py-3 ${feedbackTones[tone]} ${className}`}
    >
      <Icon size={20} stroke={2} aria-hidden="true" className="shrink-0" />
      <div className="min-w-0 flex-1">
        {title && <p className="text-md font-bold">{title}</p>}
        {children && (
          <div className={`${title ? 'mt-0.5' : ''} text-md leading-[1.45]`}>
            {children}
          </div>
        )}
      </div>
    </div>
  );
}
