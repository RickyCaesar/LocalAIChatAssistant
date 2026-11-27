<?php

namespace App\Http\Middleware;

use App\Support\ClamAVScanner;
use App\Support\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ScanUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        $files = [];
        $allFiles = $request->allFiles();
        array_walk_recursive($allFiles, function ($file) use (&$files) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        });

        foreach ($files as $file) {
            if (ClamAVScanner::scanFile((string) $file->getRealPath())) {
                continue;
            }

            $name = $file->getClientOriginalName();
            NotificationService::virusDetected((int) auth()->id(), $name);

            return response()->json([
                'error' => 'Berkas "'.$name.'" ditolak oleh pemindai antivirus.',
                'code' => 'virus_detected',
            ], 422);
        }

        return $next($request);
    }
}
