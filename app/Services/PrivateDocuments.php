<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

class PrivateDocuments
{
    public function saveClientWithDocuments(array $uploads, callable $saveClient): Client
    {
        $staged = [];
        $oldPaths = [];
        try {
            // Write every file before creating or changing the client record.
            foreach ($uploads as $kind => $file) {
                $path = $file->store('documents', 'local');
                if (! $path) {
                    throw \League\Flysystem\UnableToWriteFile::atLocation('documents');
                }
                $staged[$kind] = ['path' => $path, 'file' => $file];
            }
            $client = DB::transaction(function () use ($saveClient, $staged, &$oldPaths) {
                $client = $saveClient();
                foreach ($staged as $kind => ['path' => $path, 'file' => $file]) {
                    $query = Document::where('client_id', $client->id)->where('kind', $kind);
                    $oldPaths = array_merge($oldPaths, (clone $query)->pluck('path')->all());
                    $query->delete();
                    Document::create(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'kind' => $kind,
                        'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150),
                        'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
                    Ledger::audit('document', 'Updated '.$kind, $client->id);
                }

                return $client;
            });
        } catch (\Throwable $e) {
            $this->cleanup(array_column($staged, 'path'));
            if ($e instanceof FilesystemException) {
                report($e);
                throw ValidationException::withMessages(['upload' => 'The documents could not be saved. No client details or documents were changed. Please reselect the files and try again. If this continues, ask your administrator to check server storage permissions and free space.']);
            }
            throw $e;
        }
        // Never remove the previous files until all database changes succeed.
        $this->cleanup($oldPaths);

        return $client;
    }

    private function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (FilesystemException $e) {
                // A cleanup failure must not hide the original error or undo a successful save.
                report($e);
            }
        }
    }

    public function replace(UploadedFile $file, string $kind, ?int $clientId): Document
    {
        try {
            $path = $file->store('documents', 'local');
        } catch (FilesystemException $e) {
            report($e);
            throw ValidationException::withMessages([
                'upload' => 'The document could not be saved to server storage. Contact your administrator to check storage permissions and free disk space. Other form details may already be saved; check the client or settings page before retrying. Your previous document has not been replaced.',
            ]);
        }
        try {
            [$document, $old] = DB::transaction(function () use ($file, $kind, $clientId, $path) {
                $query = Document::where('kind', $kind)->where('client_id', $clientId);
                $old = (clone $query)->get();
                $query->delete();
                $document = Document::create(['id' => (string) Str::uuid(), 'client_id' => $clientId, 'kind' => $kind, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150), 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
                Ledger::audit('document', 'Updated '.($kind === 'qr' ? 'payment QR code' : $kind), $clientId);

                return [$document, $old];
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        foreach ($old as $previous) {
            Storage::disk('local')->delete($previous->path);
        }

        return $document;
    }
}
