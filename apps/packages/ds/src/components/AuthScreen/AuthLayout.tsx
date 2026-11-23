import { useEffect, useState, type ReactNode } from 'react';
import { Button } from '../Button/Button';
import { Link } from '../Link/Link';

const documents = [
  'Публичная оферта',
  'Пользовательское соглашение',
  'Политика конфиденциальности',
  'Обработка персональных данных',
  'Политика cookie',
];
function readConsent() {
  const value = document.cookie
    .split('; ')
    .find((item) => item.startsWith('vf_cookie_consent='))
    ?.split('=')[1];
  return value === 'all' || value === 'necessary' ? value : null;
}
function CookieConsent() {
  const [consent, setConsent] = useState(readConsent);
  useEffect(() => {
    if (consent) document.documentElement.dataset.cookieConsent = consent;
  }, [consent]);
  if (consent) return null;
  const choose = (level: 'all' | 'necessary') => {
    document.cookie = `vf_cookie_consent=${level}; Max-Age=${180 * 86400}; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    document.documentElement.dataset.cookieConsent = level;
    document.dispatchEvent(
      new CustomEvent('vf:cookie-consent', { detail: { level } }),
    );
    setConsent(level);
  };
  return (
    <div className="ds-auth-cookie" role="region" aria-label="Файлы cookie">
      <p>
        Мы используем cookie: необходимые — для входа и безопасности,
        аналитические — чтобы улучшать сервис.
      </p>
      <div className="ds-auth-cookie-actions">
        <Button
          variant="secondary"
          size="sm"
          onClick={() => choose('necessary')}
        >
          Только необходимые
        </Button>
        <Button size="sm" onClick={() => choose('all')}>
          Принять все
        </Button>
      </div>
    </div>
  );
}
interface AuthLayoutProps {
  children: ReactNode;
}
/** Shared product auth shell; no router, API, or invented legal endpoints. */
export function AuthLayout({ children }: AuthLayoutProps) {
  return (
    <div className="ds-auth">
      <header className="ds-auth-header">
        <Link href="/" aria-label="Ваш Финдир — на главную">
          <img
            src="/brand/logo-full.svg"
            width="145"
            height="32"
            alt="Ваш Финдир"
          />
        </Link>
        {/* TODO(ds): account registration URL is absent; hide the signup action. */}
      </header>
      <main className="ds-auth-main">{children}</main>
      <footer className="ds-auth-footer">
        {/* TODO(ds): /legal/* routes are absent in apps; document names remain plain text. */}
        <div className="ds-auth-documents">
          {documents.map((name) => (
            <span key={name}>{name}</span>
          ))}
        </div>
        {/* TODO(ds): company details/copyright holder are not configured for apps; do not show demo requisites. */}
        <p className="ds-auth-note">
          Персональные данные граждан РФ обрабатываются и хранятся на серверах в
          России. Сервис не является финансовой или налоговой консультацией.
        </p>
        <p className="ds-auth-copyright">
          © {new Date().getFullYear()} Ваш Финдир
        </p>
      </footer>
      <CookieConsent />
    </div>
  );
}
