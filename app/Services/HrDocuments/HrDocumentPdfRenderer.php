<?php

namespace App\Services\HrDocuments;

use App\Enums\HrGeneratedDocumentStatus;
use App\Models\HrGeneratedDocument;
use App\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Checkpoint 35 — renders a HrGeneratedDocument's already-resolved
 * rendered_content into PDF bytes, on demand, never stored. This is the
 * entire PDF attack surface, so every option here is deliberate:
 *
 * - isRemoteEnabled stays false (dompdf's default) — nothing in the PDF
 *   pipeline may fetch a remote URL (no SSRF via a crafted template or
 *   employee field).
 * - isJavascriptEnabled stays false — dompdf's limited JS subset (used
 *   by some templates for dynamic page numbers) is never needed here
 *   and is turned off explicitly rather than relying on a default.
 * - Every interpolated value is HTML-escaped before being placed in the
 *   markup this class builds — the HTML template is entirely
 *   code-owned; template `content_template` text and employee data are
 *   never passed through as raw HTML, mirroring the "never trust
 *   content as markup" rule the frontend already applies (no
 *   dangerouslySetInnerHTML — see PlaceholderRenderer/docs/security.md).
 */
class HrDocumentPdfRenderer
{
    public static function render(HrGeneratedDocument $document, Tenant $tenant): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::buildHtml($document, $tenant));
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private static function buildHtml(HrGeneratedDocument $document, Tenant $tenant): string
    {
        $title = e($document->title);
        $employeeName = e($document->employee?->fullName() ?? 'Unknown employee');
        $tenantName = e($tenant->name);
        $generatedAt = e($document->generated_at?->toFormattedDateString() ?? '—');
        $content = nl2br(e($document->rendered_content));
        $watermark = self::watermarkBanner($document->status);

        return <<<HTML
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
                    h1 { font-size: 16px; margin-bottom: 4px; }
                    .meta { color: #64748b; margin-bottom: 24px; font-size: 11px; }
                    .content { line-height: 1.6; white-space: pre-wrap; }
                    .watermark { background: #fef3c7; color: #92400e; padding: 8px 12px; margin-bottom: 16px; font-size: 11px; font-weight: bold; border-radius: 4px; }
                </style>
            </head>
            <body>
                {$watermark}
                <h1>{$title}</h1>
                <div class="meta">
                    {$employeeName} &middot; {$tenantName} &middot; Generated {$generatedAt}
                </div>
                <div class="content">{$content}</div>
            </body>
            </html>
            HTML;
    }

    /**
     * Checkpoint 37 — Option A (approved): a PDF may be downloaded at any
     * status (preview before/around approval is a real, useful HR
     * workflow step), but anything not yet `approved` is visibly labeled
     * as such — plain text, no images, nothing that could be mistaken for
     * an official watermark/seal, just a clear banner so an unapproved
     * letter is never confused for a final one.
     */
    private static function watermarkBanner(HrGeneratedDocumentStatus $status): string
    {
        if ($status === HrGeneratedDocumentStatus::Approved) {
            return '';
        }

        $label = match ($status) {
            HrGeneratedDocumentStatus::Draft => 'DRAFT — NOT YET SUBMITTED FOR APPROVAL',
            HrGeneratedDocumentStatus::PendingApproval => 'PENDING APPROVAL — NOT YET APPROVED',
            HrGeneratedDocumentStatus::Rejected => 'REJECTED — NOT APPROVED',
            HrGeneratedDocumentStatus::Archived => 'ARCHIVED',
            HrGeneratedDocumentStatus::Approved => '',
        };

        return '<div class="watermark">'.e($label).'</div>';
    }
}
