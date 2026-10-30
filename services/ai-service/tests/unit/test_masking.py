import pytest

from ai_service.privacy.masking import Masker


@pytest.mark.parametrize(
    ("text", "expected"),
    [
        ("Оплата от Иванов Иван Иванович по счёту", "Оплата от [PERSON_1] по счёту"),
        ("Перевод Петрова А.В.", "Перевод [PERSON_1]"),
        ("ПЕРЕВОД ОТ ИВАНОВ ИВАН ИВАНОВИЧ", "ПЕРЕВОД ОТ [PERSON_1]"),
        ("от иванов иван иванович", "от [PERSON_1]"),
        ("Получатель ИВАНОВ И.И.", "Получатель [PERSON_1]"),
        ("ПЕТРОВА АННА СЕРГЕЕВНА, возврат", "[PERSON_1], возврат"),
        ("звонить +7 (912) 345-67-89", "звонить [PHONE_1]"),
        ("тел. 89123456789", "тел. [PHONE_1]"),
        ("р/с 40702810900000012345", "р/с [ACCOUNT_1]"),
        ("карта 4276 1234 5678 9012", "карта [CARD_1]"),
        ("ИНН 500100732259", "ИНН [INN_1]"),
        ("почта ivan.petrov@mail.ru", "почта [EMAIL_1]"),
    ],
)
def test_masks_personal_data(text: str, expected: str) -> None:
    assert Masker().mask_text(text) == expected


@pytest.mark.parametrize(
    "text",
    [
        "ИНН организации 7707083893",  # 10 цифр — не ПДн
        "Оплата по договору 15 от 01.10.2026, сумма 12500.00",
        "ООО Ромашка",
        "доставка песок кирпич",
        "ООО СТРОЙМАТЕРИАЛЫ ПЛЮС",
    ],
)
def test_keeps_non_personal_data(text: str) -> None:
    assert Masker().mask_text(text) == text


def test_same_value_gets_same_token_within_task() -> None:
    masker = Masker()
    first = masker.mask_text("+79123456789 и снова +79123456789, другой +79990001122")
    assert first == "[PHONE_1] и снова [PHONE_1], другой [PHONE_2]"
    assert masker.replacements == 2


def test_mask_json_skips_listed_keys() -> None:
    payload = {"id": "500100732259", "amount": "12500.00", "purpose": "ИНН 500100732259", "items": ["+79123456789"]}
    masked = Masker().mask_json(payload, skip_keys={"id", "amount"})
    assert masked == {"id": "500100732259", "amount": "12500.00", "purpose": "ИНН [INN_1]", "items": ["[PHONE_1]"]}


def test_mask_json_masks_personal_data_given_as_numbers() -> None:
    masked = Masker().mask_json({"inn": 500100732259, "phone": 79123456789, "qty": 3, "ok": True}, skip_keys=set())
    assert masked == {"inn": "[INN_1]", "phone": "[PHONE_1]", "qty": 3, "ok": True}
