<?php

namespace App\Enums;

/**
 * Shared by hr_document_templates.document_type and
 * hr_generated_documents.document_type — a generated document copies its
 * template's document_type at generation time rather than looking it up
 * live, so the values must line up exactly.
 */
enum HrDocumentType: string
{
    case EmploymentLetter = 'employment_letter';
    case OfferLetter = 'offer_letter';
    case ConfirmationLetter = 'confirmation_letter';
    case PromotionLetter = 'promotion_letter';
    case WarningLetter = 'warning_letter';
    case ExitLetter = 'exit_letter';
    case ReferenceLetter = 'reference_letter';
    case ContractorEngagementLetter = 'contractor_engagement_letter';
}
