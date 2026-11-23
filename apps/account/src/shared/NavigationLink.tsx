import { useHref, useLinkClickHandler } from 'react-router';
import { Link, type LinkProps } from '@vashfindir/ds';

interface NavigationLinkProps extends Omit<LinkProps, 'href' | 'external'> {
  href: string;
}
/** Keep DS link visuals and native modifier clicks with SPA navigation. */
export function NavigationLink({
  href,
  onClick,
  ...props
}: NavigationLinkProps) {
  const resolved = useHref(href);
  const navigate = useLinkClickHandler<HTMLAnchorElement>(
    href,
    props.target ? { target: props.target } : {},
  );
  return (
    <Link
      {...props}
      href={resolved}
      onClick={(event) => {
        onClick?.(event);
        if (!event.defaultPrevented && props.download === undefined)
          navigate(event);
      }}
    />
  );
}
