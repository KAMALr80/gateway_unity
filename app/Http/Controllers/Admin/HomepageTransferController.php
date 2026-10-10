<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageConfig;
use App\Models\HomepageImport;
use App\Services\HomepageTransfer\HomepageExporter;
use App\Services\HomepageTransfer\HomepageImporter;
use App\Services\HomepageTransfer\HomepageTransferException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Homepage import / export for administrators (same session, CSRF and admin middleware as the rest of /admin).
 * Business rules live in App\Services\HomepageTransfer; this class only translates HTTP.
 */
class HomepageTransferController extends Controller
{
    public function __construct(
        private readonly HomepageExporter $exporter,
        private readonly HomepageImporter $importer,
    ) {}

    public function index()
    {
        return Inertia::render('admin/homepage/transfer', [
            'configs' => HomepageConfig::orderBy('client_key')->get(['id', 'client_key', 'label', 'is_active']),
            'imports' => HomepageImport::latest('id')->limit(30)->get()->map(fn (HomepageImport $i) => $this->present($i))->all(),
            'limits' => ['max_upload_mb' => (int) floor(config('homepage_transfer.max_upload_kb') / 1024)],
        ]);
    }

    public function export(Request $request, HomepageConfig $config): BinaryFileResponse|JsonResponse
    {
        return $this->guard(function () use ($request, $config) {
            $package = $this->exporter->export($config->client_key);
            Log::info('homepage_transfer.exported', ['client' => $config->client_key, 'export' => $package['manifest']['export_id'], 'admin' => $request->user()->id, 'includes' => $package['manifest']['includes']]);

            return response()->download($package['path'], $package['filename'], [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ])->deleteFileAfterSend(true);
        });
    }

    /** Step 1: upload, validate and preview. Changes nothing on the homepage. */
    public function validatePackage(Request $request): JsonResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:'.(int) config('homepage_transfer.max_upload_kb')]]);

        return $this->guard(function () use ($request) {
            $file = $request->file('package');

            // The extension and client MIME type are not trusted: only the ZIP signature and the real archive count.
            $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4);
            if ($head !== "PK\x03\x04") {
                throw new HomepageTransferException('archive.unreadable', 'The file is not a ZIP package.', 422);
            }

            $import = $this->importer->validateUpload($file->getRealPath(), $request->user()->id);

            return response()->json(['data' => $this->present($import)], $import->status === HomepageImport::INVALID ? 422 : 200);
        });
    }

    public function show(HomepageImport $import): JsonResponse
    {
        return response()->json(['data' => $this->present($import)]);
    }

    /** Step 2: apply the validated package. Bound to the validation id and the preview fingerprint. */
    public function confirm(Request $request, HomepageImport $import): JsonResponse
    {
        $data = $request->validate([
            'base_fingerprint' => ['required', 'string', 'size:64'],
            'confirm_replace' => ['required', 'boolean'],
        ]);

        return $this->guard(function () use ($request, $import, $data) {
            $done = $this->importer->confirm($import, $request->user()->id, $data['base_fingerprint'], (bool) $data['confirm_replace']);

            return response()->json(['data' => $this->present($done)], $done->status === HomepageImport::SUCCEEDED ? 200 : 409);
        });
    }

    public function rollback(Request $request, HomepageImport $import): JsonResponse
    {
        $data = $request->validate(['acknowledge_overwrite' => ['sometimes', 'boolean']]);

        return $this->guard(function () use ($request, $import, $data) {
            $done = $this->importer->rollback($import, $request->user()->id, (bool) ($data['acknowledge_overwrite'] ?? false));

            return response()->json(['data' => $this->present($done)], $done->status === HomepageImport::SUCCEEDED ? 200 : 409);
        });
    }

    /** Turns a refusal into a stable JSON error; anything unexpected becomes a generic 500 with no internals. */
    private function guard(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (HomepageTransferException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => ['code' => 'transfer.failed', 'message' => 'The operation failed. Nothing was changed unless the history says otherwise.']], 500);
        }
    }

    /** What the admin UI may see: never file paths or the package contents. */
    private function present(HomepageImport $i): array
    {
        return [
            'id' => $i->uuid,
            'kind' => $i->kind,
            'client_key' => $i->client_key,
            'status' => $i->status,
            'interrupted' => $i->isInterrupted(),
            'package_id' => $i->package_id,
            'schema_version' => $i->schema_version,
            'admin_user_id' => $i->admin_user_id,
            'base_fingerprint' => $i->base_fingerprint,
            'summary' => $i->summary,
            'validation' => $i->validation,
            'verification' => $i->verification,
            'backup' => ['available' => $i->hasBackup(), 'id' => $i->backup_uuid, 'sha256' => $i->backup_sha256],
            'source_import_id' => $i->source_import_uuid,
            'can_roll_back' => in_array($i->status, [HomepageImport::SUCCEEDED, HomepageImport::RECOVERY_REQUIRED], true) && $i->hasBackup(),
            'error' => $i->error_category ? ['category' => $i->error_category, 'message' => $i->error_message] : null,
            'expires_at' => $i->expires_at?->toIso8601String(),
            'started_at' => $i->started_at?->toIso8601String(),
            'completed_at' => $i->completed_at?->toIso8601String(),
            'rolled_back_at' => $i->rolled_back_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
