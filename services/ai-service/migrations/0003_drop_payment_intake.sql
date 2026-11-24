-- Удаление ИИ-разбора платежей (2.244): ночной eval и задача parse_payment_message.
-- Неподтверждённый результат без потребителя давал бы ошибку импорта Symfony каждые 5 минут.

DROP TABLE IF EXISTS ai_eval_call, ai_eval_budget, ai_eval_run;

DELETE FROM ai_trace WHERE task_type = 'parse_payment_message';
DELETE FROM ai_result WHERE task_id IN (SELECT id FROM ai_task WHERE task_type = 'parse_payment_message');
DELETE FROM ai_task WHERE task_type = 'parse_payment_message';
