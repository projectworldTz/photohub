<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function index(): View
    {
        return view('tasks.index', ['tasks' => Task::forBusiness(app('currentBusiness')->id)->orderBy('due_at')->paginate(30)]);
    }

    public function store(Request $r): RedirectResponse
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'due_at' => 'nullable|date', 'priority' => 'required|in:low,normal,high,urgent']);
        Task::create($d + ['business_id' => app('currentBusiness')->id, 'assigned_user_id' => auth()->id()]);

        return back()->with('success', 'Task created.');
    }

    public function update(Request $r, Task $task): RedirectResponse
    {
        abort_unless($task->business_id === app('currentBusiness')->id, 404);
        $task->update($r->validate(['status' => 'required|in:todo,in_progress,completed']));

        return back();
    }
}
