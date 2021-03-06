<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Nativecolumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->text('city');
            $table->text('state');
            $table->decimal('lat', 7, 4);
            $table->decimal('lon', 7, 4);
            $table->date('date');
        });

        Schema::create('temperatures', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()
                                            ->references('id')->on('locations')
                                            ->onDelete('cascade');
            $table->unsignedSmallInteger('hour');
            $table->decimal('value', 4, 1);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('temperaturs');
        Schema::drop('locations');
    }
}
