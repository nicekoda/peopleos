<?php

namespace App\Enums;

enum HrDocumentTemplateVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
