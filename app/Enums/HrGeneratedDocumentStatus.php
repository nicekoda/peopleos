<?php

namespace App\Enums;

enum HrGeneratedDocumentStatus: string
{
    case Draft = 'draft';
    case Generated = 'generated';
    case Archived = 'archived';
}
