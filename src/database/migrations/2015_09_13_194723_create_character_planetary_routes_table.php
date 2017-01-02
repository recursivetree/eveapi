<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015, 2016, 2017  Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class CreateCharacterPlanetaryRoutesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        Schema::create('character_planetary_routes', function (Blueprint $table) {

            $table->increments('id');

            $table->bigInteger('routeID');
            $table->integer('ownerID');
            $table->integer('planetID');
            $table->bigInteger('sourcePinID');
            $table->bigInteger('destinationPinID');
            $table->integer('contentTypeID');
            $table->string('contentTypeName');
            $table->integer('quantity');
            $table->bigInteger('waypoint1');
            $table->bigInteger('waypoint2');
            $table->bigInteger('waypoint3');
            $table->bigInteger('waypoint4');
            $table->bigInteger('waypoint5');

            // Indexes
            $table->index('routeID');
            $table->index('ownerID');
            $table->index('planetID');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

        Schema::drop('character_planetary_routes');
    }
}
