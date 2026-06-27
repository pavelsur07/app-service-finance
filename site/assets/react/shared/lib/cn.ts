type ClassValue = string | undefined | null | false | 0;

export function cn(...classes: ClassValue[]): string {
  return classes.filter(Boolean).join(' ');
}
