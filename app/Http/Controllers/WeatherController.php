<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Validator;
use Log;
use DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

use App\Models\Location;
use App\Models\Temperature;
use App\MyHelpers;

/**
 * TODO: Move all logic out of controler methods into a service class.
 * Add the service into the constructor and delegate the functionality
 * to the service members.
 */
class WeatherController extends Controller
{
    /**
     * Return a list of all weather temperature points ordered by id.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $results = [];
        $dbdata = null;
        $doFilter = false;

        $lat = $request->query('lat', null);
        $lon = $request->query('lon', null);

        if ($lat === null && $lon === null) {
            // Get all
            $dbData = Location::orderBy('id')
                ->with('temps')
                ->cursor();
        } else {
            // Filter by latitude+longitude
            if (!is_numeric($lat) || !is_numeric($lon))
                return response()->json(['message' => 'Invalid latitude/longitude'], 422);
            $dbData = Location::where('lat', $lat)
                ->where('lon', $lon)
                ->orderBy('id')
                ->get();
            $doFilter = true;
        }

        foreach ($dbData as $location) {
            $results[] = $this->locationRowToTargetJson($location);
        }

        if ($doFilter and count($results) === 0)
            return response()->json(['message' => 'No data found'], 404);

        return response()->json($results);
    }


    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->input(),
            [
                'date' => 'required|date',
                'location.city' => 'required',
                'location.state' => 'required',
                'location.lat' => 'required|numeric',
                'location.lon' => 'required|numeric',
                'location.temperature.*' => 'required|numeric'
            ],
            [
                'date.required' => 'Required field',
                'date.date' => 'Invalid date',
                'location.city.required' => 'Required field',
                'location.state.required' => 'Required field',
                'location.lon.required' => 'Required field',
                'location.lat.required' => 'Required field',
            ]
        );
        // TODO: Need to validate the temperature values (count() == 24 and all numeric and in reasonable range)
        // TODO: validate of a valid state name/code and normalize to name

        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()->all()]);
            return response()->json(['errors' => $validator->errors()], 422);
        } else {
            try {
                if ($this->saveWeatherDataPoint($request->input(), true))
                    return response()->json(['status' => 'ok'], 201);
                else
                    return response()->json(['status' => 'error'], 400);
            } catch (ValidationException $e) {
                // The lat+lon, city and date may already exist
                return response()->json(['errors' => $e->errors()], 400);
            }
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    /**
     * Delete all weather data points or points in the date range and specified geo location.
     * @return \Illuminate\Http\Response
     */
    public function erase(Request $request)
    {
        $haveParams = false;
        $start = $request->query('start', null);
        $end = $request->query('end', null);
        $lat = $request->query('lat', null);
        $lon = $request->query('lon', null);

        if ($start === null && $end === null && $lat === null && $lon === null) {
            $this->eraseAll();
        } else {
            $start = MyHelpers::parseDate($start);
            $end = MyHelpers::parseDate($end);
            if (!$start || !$end)
                return response()->json(['message' => 'Invalid date format'], 422);
            if (!is_numeric($lat) || !is_numeric($lon))
                return response()->json(['message' => 'Invalid latitude/longitude'], 422);

            $this->eraseByRange($start, $end, $lat, $lon);
        }

        return response()->json(['status' => 'ok'], 200);
    }


    protected function eraseAll(): void
    {
        // XXX: The transaction and the second delete is now not actually necessary
        //      as the migration sets ON DELETE CASCADE
        // Keeping it just for the sake of it
        DB::transaction(function () {
            Temperature::query()->delete();
            Location::query()->delete();
        });
    }

    protected function eraseByRange(Carbon $start, Carbon $end, float $lat, float $lon): void
    {
        // DB::enableQueryLog();
        $affected = Location::whereRaw(
            'date >= ? and date <= ? and lat = ? and lon = ?',
            [$start->toDateString(), $end->toDateString(), $lat, $lon]
        )
            ->delete();
        // dd(DB::getQueryLog());
    }

    /**
     * Insert validated weather point into database.
     * TODO: Make sure there are no duplicates. This may be done vie a compound unique index
     * @throws ValidationException
     * @returns true if inserted or false otherwise
     */
    protected function saveWeatherDataPoint(array $data, bool $insert = false): bool
    {
        DB::transaction(function () use ($data, $insert) {
            $record = new Location();
            // This is of course bound to cause problems when inserting
            // The specs mandate handling the case with an id
            if (is_numeric($data['id'] ?? null))
                $record->id = $data['id'];
            $record->date = $data['date'];
            $record->city = $data['location']['city'];
            $record->state = $data['location']['state'];
            $record->lat = $data['location']['lat'];
            $record->lon = $data['location']['lon'];

            if ($insert && $record->id) {
                // Make sure POST does not try to insert a duplicate ID
                if (Location::find($record->id)->count())
                    return false;
            }

            try {
                $record->save();
            } catch (Throwable $e) {
                dd($e);
                return false;
            }

            foreach ($data['temperature'] as $idx => $value) {
                if ($idx < 24) {
                    $temp = new Temperature();
                    $temp->hour = $idx;
                    $temp->value = $value;
                    $record->temps()->save($temp);
                } else {
                    Log::warn('Too many temperature values (expected 24), ignoring');
                }
            }
        });
        return true;
    }

    /**
     * Puts the location data in the structure defined by the API specs.
     * @return array
     */
    protected function locationRowToTargetJson($location): array
    {
        $item = [
            'id' => $location->id,
            'date' => $location->date,
            'location' => [
                'lat' => $location->lat,
                'lon' => $location->lon,
                'city' => $location->city,
                'state' => $location->state
            ],
            'temperature' => array_fill(0, 24, null)
        ];
        foreach ($location->temps as $temp) {
            if ($temp->hour >= 0 && $temp->hour < 24) {
                $item['temperature'][$temp->hour] = $temp->value;
            }
        }

        return $item;
    }
}
