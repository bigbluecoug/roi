<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public const STATES = [
        'CO' => 'Colorado',
        'UT' => 'Utah',
        'TX' => 'Texas',
        'CA' => 'California',
        'IL' => 'Illinois',
        'GA' => 'Georgia',
        'FL' => 'Florida',
        'MO' => 'Missouri',
        'OK' => 'Oklahoma',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $stateCode = (string) $request->session()->get('current_state_code');
        if (! array_key_exists($stateCode, self::STATES)) {
            return redirect()->route('setup.state');
        }

        return view('events.index', [
            'events' => Event::query()
                ->where('active', true)
                ->where('state_code', $stateCode)
                ->withCount('captures')
                ->orderBy('name')
                ->get(),
            'states' => self::STATES,
            'stateCode' => $stateCode,
            'stateName' => self::STATES[$stateCode],
            'currentEventId' => $request->session()->get('current_event_id'),
        ]);
    }

    public function show(Request $request, Event $event): View
    {
        abort_unless($event->active && array_key_exists($event->state_code, self::STATES), 404);

        $request->session()->put('current_state_code', $event->state_code);
        $request->session()->put('current_event_id', $event->id);

        return view('events.show', [
            'event' => $event->loadCount('captures'),
            'stateName' => self::STATES[$event->state_code],
            'captures' => $event->captures()
                ->with('district')
                ->latest()
                ->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'state_code' => ['required', 'string', 'in:'.implode(',', array_keys(self::STATES))],
            'starts_on' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event = Event::create($data + ['active' => true]);

        $request->session()->put('current_state_code', $event->state_code);
        $request->session()->put('current_event_id', $event->id);

        return redirect()->route('captures.create')->with('status', 'Event ready for capture.');
    }
}
