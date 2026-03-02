<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SQL Allowed Tables
    |--------------------------------------------------------------------------
    |
    | Tables the agent is permitted to query via execute_sql_query tool.
    | This list is the single source of truth used by both SqlQueryExecutor
    | (enforcement) and DatabaseSchemaService (schema generation for the LLM).
    |
    */

    'sql_allowed_tables' => [
        'users',
        'organizations',
        'organization_user',
        'teams',
        'team_user',
        'sources',
        'source_oauths',
        'calendar_events',
        'calendar_event_profile',
        'participants',
        'transcript_entries',
        'followups',
        'methodologies',
        'profiles',
        'channels',
        'meeting_summaries',
        'meeting_tasks',
        'insight_sources',
        'insight_items',
        'insight_profiles',
        'insight_profile_history',
        'insight_relationships',
        'insight_short_term',
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Column Blacklist
    |--------------------------------------------------------------------------
    |
    | Columns the agent must never see or retrieve, keyed by table name.
    | DatabaseSchemaService excludes these from the schema description shown
    | to the LLM. SqlQueryExecutor strips them from query results and blocks
    | explicit references in SQL strings.
    |
    */

    'sql_column_blacklist' => [
        'users'        => ['password', 'remember_token'],
        'source_oauths' => ['access_token', 'refresh_token'],
    ],

];
