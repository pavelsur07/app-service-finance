import { useEffect, useRef, useState, type FormEvent } from 'react';
import { IconAlertCircle } from '@tabler/icons-react';
import { Alert } from '../Alert/Alert';
import { Button } from '../Button/Button';
import { Card } from '../Card/Card';
import { FormField } from '../FormField/FormField';
import { Input } from '../Input/Input';
import { InputGroup } from '../InputGroup/InputGroup';

export interface LoginFormProps {
  staff?: boolean;
  initialEmail?: string;
  busy: boolean;
  expired: boolean;
  onLogin: (credentials: {
    email: string;
    password: string;
  }) => Promise<string | null>;
}
const invalidCredentials = 'Неверный email или пароль.';
const credentialsHelp =
  'Проверьте раскладку и Caps Lock или восстановите пароль.';
export function LoginForm({
  staff = false,
  initialEmail = '',
  busy,
  expired,
  onLogin,
}: LoginFormProps) {
  const [email, setEmail] = useState(initialEmail);
  const [password, setPassword] = useState('');
  const [submitted, setSubmitted] = useState(false);
  const [emailBlurred, setEmailBlurred] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [failureCount, setFailureCount] = useState(0);
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);
  const locked = busy || pending;
  const emailError = !email.trim()
    ? 'Укажите email'
    : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      ? 'Проверьте адрес: нужен формат name@company.ru'
      : '';
  const passwordError = password ? '' : 'Введите пароль';
  useEffect(() => {
    if (expired) passwordRef.current?.focus();
  }, [expired]);
  useEffect(() => {
    if (error && !locked) passwordRef.current?.focus();
  }, [error, locked, failureCount]);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (locked) return;
    setSubmitted(true);
    if (emailError || passwordError) {
      (emailError ? emailRef : passwordRef).current?.focus();
      return;
    }
    setError(null);
    setPending(true);
    try {
      const message = await onLogin({ email, password });
      if (message) {
        setPassword('');
        setError(message);
        setFailureCount((count) => count + 1);
        setSubmitted(false);
      }
    } catch (cause) {
      setPassword('');
      setError(
        cause instanceof Error ? cause.message : 'Не удалось выполнить запрос.',
      );
      setSubmitted(false);
    } finally {
      setPending(false);
    }
  };
  const fieldError = (message: string) =>
    message ? (
      <span className="ds-auth-field-error" role="alert">
        <IconAlertCircle size={16} stroke={2} aria-hidden="true" />
        {message}
      </span>
    ) : undefined;
  return (
    <Card className="ds-auth-card">
      <h1 className="ds-auth-title">
        {staff ? 'Вход для сотрудников' : 'Вход'}
      </h1>
      <p className="ds-auth-subtitle">
        {staff
          ? 'Администрирование «Ваш Финдир»'
          : 'Клиентский кабинет «Ваш Финдир»'}
      </p>
      {expired && (
        <Alert tone="info" className="ds-auth-alert">
          Сессия истекла. Войдите снова — вернём вас на ту же страницу.
        </Alert>
      )}
      {error && (
        <Alert tone="danger" className="ds-auth-alert">
          {error === invalidCredentials ? (
            <>
              <strong>{invalidCredentials}</strong> {credentialsHelp}
            </>
          ) : (
            error
          )}
        </Alert>
      )}
      <form
        className="ds-auth-form"
        onSubmit={(event) => void submit(event)}
        noValidate
        aria-busy={locked}
      >
        <FormField
          id="email"
          label="Email"
          error={fieldError(submitted || emailBlurred ? emailError : '')}
        >
          <Input
            ref={emailRef}
            name="email"
            type="email"
            autoComplete="username"
            placeholder="name@company.ru"
            maxLength={180}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            onBlur={() => setEmailBlurred(true)}
            disabled={locked}
            aria-required="true"
          />
        </FormField>
        {/* TODO(ds): forgot-password URL and remember-me API option are absent. */}
        <FormField
          id="password"
          label="Пароль"
          error={fieldError(submitted ? passwordError : '')}
        >
          <InputGroup
            variant="auth"
            ref={passwordRef}
            name="password"
            type="password"
            autoComplete="current-password"
            maxLength={4096}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            disabled={locked}
            aria-required="true"
          />
        </FormField>
        <Button type="submit" size="lg" loading={locked}>
          {locked ? 'Входим…' : 'Войти'}
        </Button>
      </form>
      <p className="ds-auth-legal">
        Входя в сервис, вы подтверждаете, что ознакомились с Пользовательским
        соглашением и Политикой конфиденциальности.
      </p>
    </Card>
  );
}
