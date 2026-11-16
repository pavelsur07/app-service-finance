import { useState, type ComponentPropsWithRef } from 'react';
export interface AvatarProps extends ComponentPropsWithRef<'span'> {
  name: string;
  src?: string;
  size?: 'sm' | 'md' | 'lg';
  shape?: 'circle' | 'square';
}
export function Avatar({
  name,
  src,
  size = 'md',
  shape = 'circle',
  className = '',
  ...props
}: AvatarProps) {
  const [failed, setFailed] = useState<string>();
  const initials =
    name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => Array.from(part)[0] ?? '')
      .join('')
      .toLocaleUpperCase('ru-RU') || '?';
  const sizes = {
    sm: 'size-6 text-2xs',
    md: 'size-8 text-sm',
    lg: 'size-10 text-lg',
  };
  return (
    <span
      role="img"
      aria-label={name}
      {...props}
      className={`inline-flex shrink-0 items-center justify-center overflow-hidden bg-primary font-bold text-fg-inverse ${sizes[size]} ${shape === 'circle' ? 'rounded-full' : 'rounded-md'} ${className}`}
    >
      {src && failed !== src ? (
        <img
          src={src}
          alt=""
          className="size-full object-cover"
          onError={() => setFailed(src)}
        />
      ) : (
        initials
      )}
    </span>
  );
}
