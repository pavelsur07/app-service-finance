/**
 * «Участники компании» (templates/company/company_member/index.html.twig): .js-copy-invite копирует ссылку
 * приглашения из data-copy, «Скопировано» на 1,5 с, затем «Копировать». Корень — [data-company-members].
 */
export function initCompanyMembers() {
  if (!document.querySelector('[data-company-members]')) return;
  document.querySelectorAll('.js-copy-invite').forEach((button) => {
    button.addEventListener('click', () => {
      const value = button.getAttribute('data-copy');
      if (!value) return;
      navigator.clipboard.writeText(value);
      button.textContent = 'Скопировано';
      setTimeout(() => { button.textContent = 'Копировать'; }, 1500);
    });
  });
}
