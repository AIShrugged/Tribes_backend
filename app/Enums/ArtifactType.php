<?php

namespace App\Enums;

enum ArtifactType: string
{
    case TaskTable      = 'task_table';
    case MeetingCard    = 'meeting_card';
    case PeopleList     = 'people_list';
    case InsightCard    = 'insight_card';
    case Chart          = 'chart';
    case TranscriptView        = 'transcript_view';
    case MethodologyCriteria   = 'methodology_criteria';
    case DecisionLog           = 'decision_log';
}
