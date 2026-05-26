<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function state(Request $request): View
    {
        return view('setup.state', [
            'states' => EventController::STATES,
            'currentStateCode' => $request->session()->get('current_state_code'),
        ]);
    }

    public function storeState(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'state_code' => ['required', 'string', 'in:'.implode(',', array_keys(EventController::STATES))],
        ]);

        $request->session()->put('current_state_code', $data['state_code']);
        $request->session()->forget('current_event_id');

        return redirect()->route('setup.events');
    }

    public function selectEvent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'exists:events,id'],
        ]);

        $event = Event::query()->where('active', true)->findOrFail($data['event_id']);

        $request->session()->put('current_state_code', $event->state_code);
        $request->session()->put('current_event_id', $event->id);

        return redirect()->route('captures.create');
    }
}
