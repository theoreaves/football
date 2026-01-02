<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PdfLibraryController extends Controller
{
    public function index()
    {
        return view('pdf-library.index');
    }

    public function list()
    {
        $dir = public_path('pdfs');

        if (!is_dir($dir)) {
            return response()->json([
                'ok' => true,
                'files' => [],
            ]);
        }

        $files = collect(glob($dir . '/*.pdf'))
            ->map(function ($path) {
                $name = basename($path);
                return [
                    'name' => $name,
                    'label' => Str::of($name)->replace('_', ' ')->toString(),
                    'url' => asset('pdfs/' . $name),
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json([
            'ok' => true,
            'files' => $files,
        ]);
    }
}
