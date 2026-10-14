<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Services\Restore;

enum SelectionKind: string
{
    case Everything = 'everything';
    case Paths = 'paths';
    case Database = 'database';
    case Stdout = 'stdout';
}
