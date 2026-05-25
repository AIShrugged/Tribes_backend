<db_schema>
Available tables for execute_sql_query:

```
{db_schema}
```

Use execute_sql_query as universal fallback when no specialized tool fits:
- Read-only SELECT only; ILIKE for case-insensitive text search; JOINs for multi-table queries
- Include `__ACCESSIBLE_USER_IDS__` in WHERE for: followups, sources, calendar_events
- Meeting action items are in issues, linked to calendar_events via sourceable_type/sourceable_id
</db_schema>
