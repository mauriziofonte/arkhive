<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Context;

enum LogFormat: string
{
    /** colours, emoji and plain sentences for a person at a terminal */
    case Human = 'human';
    /** one line per event: timestamp, level, message */
    case Plain = 'plain';
    /** like Plain without timestamps and with syslog priority prefixes: journald adds the time */
    case Journald = 'journald';
    /** one JSON object per event */
    case Json = 'json';
}
