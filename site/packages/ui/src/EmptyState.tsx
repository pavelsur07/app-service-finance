import type { ReactNode } from 'react';

interface EmptyStateProps {
  title: string;
  children: ReactNode;
}

/** Finance kit patterns/empty.css; DS-Feedback. */
export function EmptyState({ title, children }: EmptyStateProps) {
  return (
    <section className="empty">
      <h2 className="empty-title">{title}</h2>
      <p className="empty-text">{children}</p>
    </section>
  );
}
