## Входные данные
1. Текст методологии:
{{ $methodology }}

2. Транскрипт встречи:
{{ $transcript }}

3. ID будущего артефакта:
{{ $artifact_id }}

4. Заголовок артефакта:
{{ $artifact_title }}

---

## Задача ИИ
1. На основе методологии и транскрипта сгенерируй один артефакт в формате webchat.
2. Не используй старую схему `total_score / metrics / conclusion`.
3. Верни только JSON в формате artifact state.
4. Артефакт должен иметь тип `methodology_criteria`.
5. В `data.blocks` используй block-based структуру методологии.

## Block schema
{{ $artifact_schema }}

## Выходной формат: JSON
```json
{
  "artifacts": {
    "{{ $artifact_id }}": {
      "id": "{{ $artifact_id }}",
      "type": "methodology_criteria",
      "title": "{{ $artifact_title }}",
      "data": {
        "blocks": [
          {
            "type": "header",
            "text": "..."
          }
        ]
      },
      "status": "ready"
    }
  },
  "layout": {
    "items": [
      {"id": "{{ $artifact_id }}"}
    ]
  }
}
```

## Требования
- Пиши на русском языке.
- Сохраняй иерархию метрик методологии.
- Не добавляй поля вне указанной схемы.
- Возвращай только валидный JSON.
