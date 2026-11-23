import { cloneElement, useId, type ReactElement, type ReactNode } from 'react';

interface FieldControlProps {
  id?: string;
  required?: boolean;
  'aria-describedby'?: string;
  'aria-invalid'?: boolean | 'true' | 'false' | 'grammar' | 'spelling';
}
export interface FormFieldProps {
  id?: string;
  label: ReactNode;
  required?: boolean;
  helper?: ReactNode;
  error?: ReactNode;
  children: ReactElement<FieldControlProps>;
  className?: string;
}
/** DS-Controls: one labelled control, with merged helper/error descriptions. */
export function FormField({
  id,
  label,
  required,
  helper,
  error,
  children,
  className = '',
}: FormFieldProps) {
  const generated = useId();
  if (id && children.props.id && id !== children.props.id)
    throw new Error('FormField id differs from child id');
  const controlId = id ?? children.props.id ?? generated;
  const hasError =
    error !== undefined && error !== null && error !== false && error !== '';
  const hasHelper =
    helper !== undefined &&
    helper !== null &&
    helper !== false &&
    helper !== '';
  const describedBy = [
    children.props['aria-describedby'],
    hasHelper ? `${controlId}-helper` : '',
    hasError ? `${controlId}-error` : '',
  ]
    .filter(Boolean)
    .join(' ');
  const isRequired = required ?? children.props.required;
  return (
    <div className={`form-field ${className}`}>
      <div className="flex items-center gap-0.5">
        <label className="form-label" htmlFor={controlId}>
          {label}
        </label>
        {isRequired && (
          <span aria-hidden="true" className="text-danger-dot">
            *
          </span>
        )}
      </div>
      {cloneElement(children, {
        id: controlId,
        ...(isRequired !== undefined ? { required: isRequired } : {}),
        ...(describedBy ? { 'aria-describedby': describedBy } : {}),
        ...(hasError ? { 'aria-invalid': true } : {}),
      })}
      {hasHelper && (
        <p id={`${controlId}-helper`} className="text-xs text-fg-muted">
          {helper}
        </p>
      )}
      {hasError && (
        <p id={`${controlId}-error`} className="form-error text-xs">
          {error}
        </p>
      )}
    </div>
  );
}
