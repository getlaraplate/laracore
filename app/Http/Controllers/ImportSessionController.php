<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Requests\ImportMappingRequest;
use Modules\Core\Http\Requests\ImportUploadRequest;
use Modules\Core\Import\Enums\ImportSessionStatus;
use Modules\Core\Import\Enums\ImportSourceFormat;
use Modules\Core\Import\Support\EntityImporterRegistry;
use Modules\Core\Import\Support\ImportLauncher;
use Modules\Core\Import\Support\ImportPreviewService;
use Modules\Core\Import\ValueObjects\ImportField;
use Modules\Core\Models\ImportSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SPA-facing API for the interactive bulk import: list importable entities,
 * upload a file (detecting its columns), preview the mapping grid, save the
 * mapping, launch the queued job, poll status, and download the failure report.
 */
final class ImportSessionController extends Controller
{
    public function __construct(private readonly ImportPreviewService $preview) {}

    /**
     * The registered importable entities and their target fields, for the picker.
     */
    public function entities(EntityImporterRegistry $registry): JsonResponse
    {
        $entities = [];

        foreach ($registry->all() as $importer) {
            $entities[] = [
                'key' => $importer->key(),
                'label' => $importer->label(),
                'fields' => array_map(static fn (ImportField $field): array => $field->toArray(), $importer->fields()),
            ];
        }

        return response()->json(['data' => $entities]);
    }

    /**
     * Upload a file and open a draft session, detecting its source columns.
     */
    public function store(ImportUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $format = ImportSourceFormat::fromExtension($extension) ?? ImportSourceFormat::Csv;
        $disk = (string) (config('core.import.disk') ?? 'local');

        $session = ImportSession::query()->create([
            'user_id' => $request->user()?->getKey(),
            'entity_key' => (string) $request->string('entity_key'),
            'source_format' => $format,
            'file_disk' => $disk,
            'file_path' => (string) $file->store('imports', $disk),
            'original_filename' => $file->getClientOriginalName(),
            'options' => $request->array('options'),
        ]);

        $this->preview->detectColumns($session);

        return response()->json(['data' => $this->payload($session->refresh())], Response::HTTP_CREATED);
    }

    /**
     * Current status and counters, for progress polling.
     */
    public function show(ImportSession $import): JsonResponse
    {
        return response()->json(['data' => $this->payload($import)]);
    }

    /**
     * The mapping grid: columns, sample rows, target fields and a suggested mapping.
     */
    public function preview(ImportSession $import, Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->integer('limit', 20)));

        return response()->json(['data' => $this->preview->preview($import, $limit)->toArray()]);
    }

    /**
     * Persist the chosen mapping. Rejected while the import is already running.
     */
    public function saveMapping(ImportMappingRequest $request, ImportSession $import): JsonResponse
    {
        if ($import->status->isTerminal() || $import->status === ImportSessionStatus::Processing) {
            return response()->json(['message' => 'This import can no longer be mapped.'], Response::HTTP_CONFLICT);
        }

        $mapping = array_filter(
            $request->array('mapping'),
            static fn (mixed $column): bool => is_string($column) && $column !== '',
        );

        $import->forceFill(['mapping' => $mapping, 'status' => ImportSessionStatus::Draft])->save();

        return response()->json(['data' => $this->payload($import)]);
    }

    /**
     * Queue the import. Requires a mapping that covers every required field.
     */
    public function run(ImportSession $import, ImportLauncher $launcher): JsonResponse
    {
        if (! $launcher->isLaunchable($import)) {
            return response()->json(['message' => 'This import is already running or finished.'], Response::HTTP_CONFLICT);
        }

        $missing = $launcher->missingRequiredFields($import);

        if ($missing !== []) {
            return response()->json([
                'message' => 'Map every required field before running the import.',
                'errors' => ['mapping' => $missing],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $launcher->queue($import);

        return response()->json(['data' => $this->payload($import)]);
    }

    /**
     * Download the per-row failure report as CSV.
     */
    public function errors(ImportSession $import): StreamedResponse
    {
        $filename = 'import-' . $import->getKey() . '-errors.csv';

        return response()->streamDownload(function () use ($import): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['row_number', 'field', 'messages', 'raw']);

            $import->rowErrors()->orderBy('row_number')->chunk(500, static function (Collection $errors) use ($handle): void {
                foreach ($errors as $error) {
                    foreach ($error->errors as $field => $messages) {
                        fputcsv($handle, [
                            $error->row_number,
                            $field,
                            implode('; ', (array) $messages),
                            (string) json_encode($error->raw),
                        ]);
                    }
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ImportSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'entity_key' => $session->entity_key,
            'source_format' => $session->source_format->value,
            'original_filename' => $session->original_filename,
            'status' => $session->status->value,
            'detected_columns' => $session->detected_columns ?? [],
            'mapping' => $session->mapping ?? [],
            'counters' => [
                'total' => $session->total_rows,
                'processed' => $session->processed_rows,
                'created' => $session->created_rows,
                'updated' => $session->updated_rows,
                'skipped' => $session->skipped_rows,
                'failed' => $session->failed_rows,
            ],
            'started_at' => $session->started_at?->toIso8601String(),
            'finished_at' => $session->finished_at?->toIso8601String(),
        ];
    }
}
