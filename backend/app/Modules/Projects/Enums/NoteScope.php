<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * The two note scopes. Both are private to their author; the scope only decides
 * where the note surfaces and whether it is tied to a project.
 */
enum NoteScope: string
{
    /** One per user — shown on the dashboard and in the task views panel. */
    case USER = 'user';

    /** One per user per project — shown on that project's detail page. */
    case PROJECT = 'project';
}
