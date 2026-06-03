<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EventController extends Controller
{
    public const STATES = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
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
