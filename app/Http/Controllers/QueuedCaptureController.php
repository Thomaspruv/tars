<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reçoit les notes prises pendant que l'appareil était hors ligne (voir
 * public/hors-ligne.html) et les verse telles quelles dans l'inbox : la
 * capture ne décide jamais de ce que la chose est, c'est le tri qui le fait.
 */
class QueuedCaptureController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.content' => ['required', 'string', 'max:5000'],
        ]);

        $stored = 0;

        foreach ($validated['items'] as $item) {
            $content = trim($item['content']);

            if ($content === '') {
                continue;
            }

            InboxItem::create(['content' => $content]);
            $stored++;
        }

        return response()->json(['stored' => $stored]);
    }
}
