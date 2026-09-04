<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        return view('notifications.index', ['notifications' => auth()->user()->notifications()->paginate(30)]);
    }

    public function read(): RedirectResponse
    {
        auth()->user()->unreadNotifications->markAsRead();

        return back();
    }

    public function show(string $notification): RedirectResponse
    {
        /** @var DatabaseNotification $record */
        $record = auth()->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return redirect()->to($record->data['url'] ?? route('notifications.index'));
    }
}
