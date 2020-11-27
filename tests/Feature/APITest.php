<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use DB;
use App\Models\Location;
use App\Models\Temperature;
use Illuminate\Support\Carbon;

use App\MyHelpers;


class APITest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setup();

        $this->cities = [];
        $handle = fopen("tests/cities.txt", "r");
        $this->assertNotEquals($handle, FALSE);
        while (($data = fgetcsv($handle)) !== FALSE) {
            $attrs = [
                'city' => trim($data[0]),
                'state' => trim($data[1]),
                'lat' => floatval(trim($data[2])),
                'lon' => floatval(trim($data[3]))
            ];
            $this->cities[] = $attrs;
        }
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function testGetAll()
    {
        Location::query()->delete();

        foreach (['2020-10-19', '2020-10-20', '2020-10-22', '2020-10-23'] as $dt) {
            $loc = new Location($this->cities[0]);
            $loc->date = $dt;
            $loc->save();
            $temps = array_fill(0, 24, 20.5);
            for ($idx = 0; $idx < 24; ++$idx) {
                $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
                $loc->temps()->save($temp);
            }
        }
        foreach (['2020-11-01', '2020-11-02', '2020-11-03', '2020-11-04', '2020-11-05'] as $dt) {
            $loc = new Location($this->cities[1]);
            $loc->date = $dt;
            $loc->save();
            $temps = array_fill(0, 24, 20.5);
            for ($idx = 0; $idx < 24; ++$idx) {
                $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
                $loc->temps()->save($temp);
            }
        }

        $response = $this->get('/api/weather');

        $response->assertStatus(200)
            ->assertJsonCount(9);
    }

    public function testGetLatLong()
    {
        Location::query()->delete();

        foreach (['2020-10-19', '2020-10-20', '2020-10-22', '2020-10-23'] as $dt) {
            $loc = new Location($this->cities[0]);
            $loc->date = $dt;
            $loc->save();
            $temps = array_fill(0, 24, 20.5);
            for ($idx = 0; $idx < 24; ++$idx) {
                $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
                $loc->temps()->save($temp);
            }
        }
        foreach (['2020-10-19', '2020-10-20', '2020-10-22'] as $dt) {
            $loc = new Location($this->cities[1]);
            $loc->date = $dt;
            $loc->save();
            $temps = array_fill(0, 24, 20.5);
            for ($idx = 0; $idx < 24; ++$idx) {
                $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
                $loc->temps()->save($temp);
            }
        }

        $response = $this->get('/api/weather?lat=33.333&lon=11.1111');
        $response->assertStatus(404);

        $response = $this->get('/api/weather?lat=31.4428&lon=-100.4503');
        $response->assertStatus(200)
            ->assertJsonCount(4);
    }

    public function testEraseAll()
    {
        $loc = new Location($this->cities[0]);
        $loc->date = '2020-10-20';
        $loc->save();
        $temps = array_fill(0, 24, 20.5);
        for ($idx = 0; $idx < 24; ++$idx) {
            $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
            $loc->temps()->save($temp);
        }

        $response = $this->delete('/api/erase');
        $response->assertStatus(200);

        $response1 = $this->get('/api/weather');
        $response->assertStatus(200)
            ->assertJson([]);
    }

    public function testEraseByRange()
    {
        Location::query()->delete();

        foreach (['2020-10-19', '2020-10-20', '2020-10-22', '2020-10-23'] as $dt) {
            $loc = new Location($this->cities[0]);
            $loc->date = $dt;
            $loc->save();
            $temps = array_fill(0, 24, 20.5);
            for ($idx = 0; $idx < 24; ++$idx) {
                $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
                $loc->temps()->save($temp);
            }
        }

        $loc = new Location($this->cities[1]);
        $loc->date = '2020-10-21';
        $loc->save();
        $temps = array_fill(0, 24, 20.5);
        for ($idx = 0; $idx < 24; ++$idx) {
            $temp = new Temperature(['hour' => $idx, 'value' => 20.5]);
            $loc->temps()->save($temp);
        }

        // $request = $this->delete('/api/erase?start=20201020&end=20201022&lat=31.4428&lon=-100.4503');
        $request = $this->delete('/api/erase?start=20201020&end=20201022&lat=31.4428&lon=-100.4503');
        $request->assertStatus(200);
        $count = Location::count();
        $this->assertEquals($count, 3);
    }

    public function testAddNew()
    {
        Location::query()->delete();

        $weatherPoint = [
            'date' => '2020-10-01',
            'location' => [
                'city' => 'test',
                'state' => 'FL',
                'lat' => 20.0,
                'lon' => 25.5
            ],
            'temperature' => array_fill(0, 24, 25.0)
        ];
        $response = $this->post('/api/weather', $weatherPoint);
        $response->assertStatus(201);

        $response1 = $this->get('/api/weather');
        $response1->assertJsonCount(1);

        // Refuse to save "POSTed" data with an id that exists
        $id = $response1->original[0]['id'];
        $weatherPoint['id'] = $id;
        $weatherPoint['location']['city'] = 'Fubar';
        $response2 = $this->post('/api/weather', $weatherPoint);

        $response2->assertStatus(400);
    }
}
