<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrawlRun;
use App\Models\Source;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index()
    {
        $sources = Source::query()->orderBy('id')->get();

        return view('admin.sources', compact('sources'));
    }

    public function scheduleRefresh(Request $request, Source $source)
    {
        CrawlRun::query()->create([
            'source_id' => $source->id,
            'status' => 'pending',
            'trigger' => 'manual',
            'progress' => ['requested_by' => $request->user()->id],
        ]);

        // Force due on next cron
        $source->update([
            'next_retry_at' => now(),
        ]);

        return back()->with('status', 'به‌روزرسانی محدود برای «'.$source->name.'» زمان‌بندی شد. کرون آن را اجرا می‌کند.');
    }
}
