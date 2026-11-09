/**
 * «Тариф и оплата» (templates/billing/client/tariff.html.twig): в карточке онлайн-оплаты [data-billing-online]
 * видна только подсказка [data-billing-plan="<тариф>:<срок>"] выбранных тарифа и срока.
 */
export function initBillingTariff() {
  document.querySelectorAll('[data-billing-online]').forEach((card) => {
    const tariff = card.querySelector('[name="tariff"]');
    const months = card.querySelector('[name="months"]');
    const update = () => {
      const key = `${tariff.value}:${months.value}`;
      card.querySelectorAll('[data-billing-plan]').forEach((el) => { el.hidden = el.dataset.billingPlan !== key; });
    };
    tariff.addEventListener('change', update);
    months.addEventListener('change', update);
    update();
  });
}
