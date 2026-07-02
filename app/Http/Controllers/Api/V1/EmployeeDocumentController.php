<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreEmployeeDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    protected const STORAGE_DISK = 'local';

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);

        $documents = $this->visibleDocuments($employee, $request)
            ->orderByDesc('created_at')
            ->paginate();

        return EmployeeDocumentResource::collection($documents);
    }

    public function store(StoreEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);

        $validated = $request->validated();
        $tenantId = app(Tenant::class)->id;

        $category = $validated['document_category_id'] ?? null
            ? DocumentCategory::query()->find($validated['document_category_id'])
            : null;

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $storedFilename = Str::random(40).'.'.$extension;
        $directory = "employee-documents/{$tenantId}/{$employee->id}";
        $storedPath = $file->storeAs($directory, $storedFilename, self::STORAGE_DISK);
        $checksum = hash_file('sha256', $file->getRealPath());

        $document = EmployeeDocument::query()->create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'document_category_id' => $category?->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'storage_disk' => self::STORAGE_DISK,
            'storage_path' => $storedPath,
            'mime_type' => $file->getMimeType(),
            'file_extension' => $extension,
            'file_size' => $file->getSize(),
            'checksum' => $checksum,
            'status' => DocumentStatus::Active,
            'is_sensitive' => $category?->is_sensitive ?? false,
            'issue_date' => $validated['issue_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document.uploaded',
            module: 'documents',
            tenantId: $tenantId,
            auditableType: EmployeeDocument::class,
            auditableId: $document->id,
            targetUserId: null,
            description: "Document '{$document->title}' uploaded for employee #{$employee->id}.",
            newValues: [
                'employee_id' => $employee->id,
                'document_category_id' => $document->document_category_id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'is_sensitive' => $document->is_sensitive,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new EmployeeDocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Request $request, Employee $employee, EmployeeDocument $document): EmployeeDocumentResource
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);
        $this->ensureDocumentBelongsToEmployee($document, $employee);
        $this->ensureCanViewDocument($document, $request);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document.viewed',
            module: 'documents',
            tenantId: $document->tenant_id,
            auditableType: EmployeeDocument::class,
            auditableId: $document->id,
            description: "Document '{$document->title}' metadata viewed.",
            metadata: ['employee_id' => $employee->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new EmployeeDocumentResource($document);
    }

    public function download(Request $request, Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);
        $this->ensureDocumentBelongsToEmployee($document, $employee);
        $this->ensureCanViewDocument($document, $request);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document.downloaded',
            module: 'documents',
            tenantId: $document->tenant_id,
            auditableType: EmployeeDocument::class,
            auditableId: $document->id,
            description: "Document '{$document->title}' downloaded.",
            metadata: [
                'employee_id' => $employee->id,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return Storage::disk($document->storage_disk)->download(
            $document->storage_path,
            $document->original_filename,
        );
    }

    public function destroy(Request $request, Employee $employee, EmployeeDocument $document): JsonResponse
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);
        $this->ensureDocumentBelongsToEmployee($document, $employee);

        $snapshot = $document->only(['title', 'document_category_id', 'status', 'is_sensitive']);

        $document->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document.deleted',
            module: 'documents',
            tenantId: $document->tenant_id,
            auditableType: EmployeeDocument::class,
            auditableId: $document->id,
            description: "Document '{$document->title}' soft-deleted.",
            oldValues: $snapshot,
            metadata: ['employee_id' => $employee->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Document deleted.']);
    }

    /**
     * Sensitive documents (is_sensitive on the document itself, inherited
     * from the category at upload time) are excluded entirely from
     * listings for users without documents.view_sensitive — not shown
     * with masked fields, since a sensitive document's mere existence can
     * itself be worth protecting.
     */
    protected function visibleDocuments(Employee $employee, Request $request)
    {
        $query = $employee->documents();

        if (! $request->user()->hasPermission('documents.view_sensitive')) {
            $query->where('is_sensitive', false);
        }

        return $query;
    }

    protected function ensureCanViewDocument(EmployeeDocument $document, Request $request): void
    {
        if ($document->is_sensitive) {
            abort_unless($request->user()->hasPermission('documents.view_sensitive'), 404);
        }
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as EmployeeController. 404, not 403: don't reveal that a
     * record exists in another tenant.
     */
    protected function ensureEmployeeBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }

    /**
     * The document must belong to the specific employee in the route, not
     * just to the current tenant — a valid document ID for a different
     * employee in the same tenant must still be rejected.
     */
    protected function ensureDocumentBelongsToEmployee(EmployeeDocument $document, Employee $employee): void
    {
        abort_unless($document->employee_id === $employee->id, 404);
    }
}
