## Input Data
1. Methodology text:
{{ $methodology }}

---

## Task for the AI
1. Extract the evaluation structure from the methodology.
2. Generate block-based JSON compatible with the `methodology_criteria` artifact in webchat.
3. Return only JSON with a `blocks` array.
4. Use the following block types:
   - `header`
   - `scoring_table`
   - `progress_summary`
   - `scale`
   - `text_list`
5. Parent metrics should show the total score for a section, while child metrics should show the score for specific components.

## blocks Structure
```json
{
  "blocks": [
    {
      "type": "header",
      "text": "Section title"
    },
    {
      "type": "scoring_table",
      "columns": ["Metric", "Score", "Max.", "Comment"],
      "rows": [
        ["Small talk", 0, 4, "Comment"]
      ]
    },
    {
      "type": "progress_summary",
      "items": [
        {
          "label": "Total score",
          "value": 0,
          "max": 72
        }
      ]
    },
    {
      "type": "scale",
      "title": "Scale",
      "items": [
        {
          "score": 0,
          "label": "Score description"
        }
      ]
    },
    {
      "type": "text_list",
      "title": "Strengths",
      "items": [
        "Text item"
      ]
    }
  ]
}
```

## Requirements
- Preserve the hierarchy of methodology metrics.
- Do not add any fields outside `blocks`.
- Return only valid JSON.
